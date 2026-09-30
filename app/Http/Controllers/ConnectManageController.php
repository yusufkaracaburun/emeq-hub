<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccountingMappingObserver;
use App\Accounting\AccountingTargetRegistry;
use App\Enums\Provider;
use App\Integrations\Exact\ExactReferenceData;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\PassThroughCall;
use App\Models\ProviderEntityLink;
use App\Support\Connect\ConnectLinkFactory;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConnectManageController extends Controller
{
    private const VAT_KEYS = ['21' => '21%', '9' => '9%', '0' => '0%', 'reverse_charge:21' => '21% verlegd', 'reverse_charge:9' => '9% verlegd'];

    private const TYPE_DEFAULT_GL_KEYS = ['sales_default', 'purchase_default', '_default'];

    private const NON_CATEGORY_GL_KEYS = ['invoice', 'self_billing', 'suspense', ...self::TYPE_DEFAULT_GL_KEYS];

    public function __construct(
        private readonly AccountingTargetRegistry $registry,
        private readonly ConnectLinkFactory $links,
    ) {}

    public function show(Request $request, Account $account, string $provider): JsonResponse
    {
        $connection = $this->resolveConnection($account, $provider);

        return response()->json([
            'connection' => [
                'provider' => $connection->provider->value,
                'label' => $connection->provider->getLabel(),
                'status' => $connection->status,
                'administratie_id' => $connection->administratie_id,
                'connected_since' => $connection->created_at?->toIso8601String(),
                'reconnect_url' => $this->links->startUrl($request, $account, $provider, 'connect.start'),
                'disconnect_url' => $this->links->startUrl($request, $account, $provider, 'connect.disconnect'),
            ],
            'bookings' => $this->bookingRows($connection),
            'relations' => $this->relationRows($connection, $request, $account, $provider),
            'settings' => $this->settingsPayload($connection, $request),
            'urls' => [
                'mapping_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.mapping'),
                'relations_search_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.relations.search'),
            ],
        ]);
    }

    public function updateMapping(Request $request, Account $account, string $provider): JsonResponse
    {
        abort_if($this->links->isViewOnly($request), 403);

        $connection = $this->resolveConnection($account, $provider);

        $journalCodes = $this->mirrorCodes($connection, ConnectionAccountingRef::KIND_JOURNAL);
        $vatCodes = $this->mirrorCodes($connection, ConnectionAccountingRef::KIND_VAT);

        $rules = [
            'journals.sales' => ['sometimes', 'nullable', 'string', Rule::in($journalCodes)],
            'journals.purchase' => ['sometimes', 'nullable', 'string', Rule::in($journalCodes)],
            'gl_accounts' => ['sometimes', 'array', function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                foreach (array_keys((array) $request->input('gl_accounts')) as $key) {
                    if (str_contains((string) $key, '.')) {
                        $fail("Categorie-sleutel '{$key}' mag geen punt bevatten.");
                    }
                }
            }],
            'gl_accounts.*' => ['nullable', 'string', Rule::in($this->mirrorCodes($connection, ConnectionAccountingRef::KIND_GL))],
        ];

        foreach (array_keys(self::VAT_KEYS) as $key) {
            $rules["vat_codes.{$key}"] = ['sometimes', 'nullable', 'string', Rule::in($vatCodes)];
        }

        $validated = $request->validate($rules);

        $metadata = $connection->metadata ?? [];
        $mapping = $metadata['accounting_mapping'] ?? [];

        foreach (['journals', 'gl_accounts', 'vat_codes'] as $section) {
            foreach ($validated[$section] ?? [] as $key => $code) {
                if ($section === 'gl_accounts' && in_array($key, self::TYPE_DEFAULT_GL_KEYS, true)) {
                    continue;
                }

                if ($code === null) {
                    unset($mapping[$section][$key]);
                } else {
                    $mapping[$section][$key] = $code;
                }
            }
        }

        $metadata['accounting_mapping'] = $mapping;
        $connection->metadata = $metadata;

        Context::addHidden(AccountingMappingObserver::ACTOR, $this->links->actor($request));
        $connection->save();

        return response()->json(['settings' => $this->settingsPayload($connection, $request)]);
    }

    public function relinkRelation(Request $request, Account $account, string $provider, ConnectionAccountingRef $ref): JsonResponse
    {
        abort_if($this->links->isViewOnly($request), 403);

        $connection = $this->resolveConnection($account, $provider);
        $this->authorizeRelationRef($connection, $ref);

        $validated = $request->validate([
            'native_id' => ['required', 'string'],
            'label' => ['sometimes', 'nullable', 'string'],
        ]);

        $ref->update([
            'native_id' => $validated['native_id'],
            'label' => $validated['label'] ?? $ref->label,
            'attrs' => ['matched_on' => 'pinned'],
            'synced_at' => now(),
        ]);

        return response()->json(['relation' => $this->relationPayload($ref->fresh(), $request, $account, $provider)]);
    }

    public function unlinkRelation(Request $request, Account $account, string $provider, ConnectionAccountingRef $ref): JsonResponse
    {
        abort_if($this->links->isViewOnly($request), 403);

        $connection = $this->resolveConnection($account, $provider);
        $this->authorizeRelationRef($connection, $ref);

        $ref->delete();

        return response()->json(['deleted' => true]);
    }

    public function searchRelations(Request $request, Account $account, string $provider): JsonResponse
    {
        $connection = $this->resolveConnection($account, $provider);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1'],
        ]);

        $matches = (new ExactReferenceData($connection))->relationsByName($validated['q']);

        return response()->json([
            'results' => array_map(static fn (array $match): array => [
                'id' => $match['id'],
                'code' => $match['code'],
                'name' => $match['name'],
            ], $matches),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function bookingRows(Connection $connection): array
    {
        $calls = PassThroughCall::query()
            ->where('connection_id', $connection->getKey())
            ->where('path', '/v1/accounting/documents')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        if ($calls->isEmpty()) {
            return [];
        }

        $byFingerprint = ProviderEntityLink::query()
            ->where('connection_id', $connection->getKey())
            ->orderByDesc('last_synced_at')
            ->get()
            ->keyBy(fn (ProviderEntityLink $link): string => substr(hash('sha256', $link->external_id), 0, 12));

        $rows = [];

        foreach ($calls as $call) {
            $link = $call->request_fingerprint === null ? null : $byFingerprint->get($call->request_fingerprint);

            $rows[] = [
                'booked_at' => $call->created_at?->toIso8601String(),
                'document' => $link === null ? null : ($link->provider_entity_number ?? $link->external_id),
                'posted' => $call->status < 400,
                'messages' => $this->bookingMessages($call),
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private function bookingMessages(PassThroughCall $call): array
    {
        $messages = array_values(array_filter(array_map(
            static fn (array $warning): ?string => isset($warning['message'])
                ? (string) $warning['message']
                : null,
            $call->warnings ?? [],
        )));

        if ($call->status < 400) {
            return $messages;
        }

        $body = json_decode((string) $call->response_body, true);
        $reason = is_array($body) ? ($body['message'] ?? null) : null;

        return [...$messages, ...($reason === null ? [] : [(string) $reason])];
    }

    private function relationRows(Connection $connection, Request $request, Account $account, string $provider): array
    {
        return ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->orderByDesc('synced_at')
            ->get()
            ->map(fn (ConnectionAccountingRef $ref): array => $this->relationPayload($ref, $request, $account, $provider))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function relationPayload(ConnectionAccountingRef $ref, Request $request, Account $account, string $provider): array
    {
        return [
            'id' => $ref->id,
            'code' => $ref->code,
            'label' => $ref->label,
            'native_id' => $ref->native_id,
            'matched_on' => $ref->attrs['matched_on'] ?? null,
            'synced_at' => $ref->synced_at?->toIso8601String(),
            'relink_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.relations.relink', ['ref' => $ref->getKey()]),
            'unlink_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.relations.unlink', ['ref' => $ref->getKey()]),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsPayload(Connection $connection, Request $request): array
    {
        $mapping = $connection->metadata['accounting_mapping'] ?? [];

        return [
            'mode' => $this->links->isViewOnly($request) ? 'view' : 'manage',
            'journals' => [
                'sales' => $mapping['journals']['sales'] ?? null,
                'purchase' => $mapping['journals']['purchase'] ?? null,
                'options' => $this->refOptions($connection, ConnectionAccountingRef::KIND_JOURNAL),
            ],
            'gl_accounts' => [
                'invoice' => $mapping['gl_accounts']['invoice'] ?? null,
                'self_billing' => $mapping['gl_accounts']['self_billing'] ?? null,
                'suspense' => $mapping['gl_accounts']['suspense'] ?? null,
                'options' => $this->refOptions($connection, ConnectionAccountingRef::KIND_GL),
            ],
            'vat_codes' => $this->vatRows($connection),
            'vat_options' => $this->refOptions($connection, ConnectionAccountingRef::KIND_VAT),
            'categories' => $this->categoryRows($connection),
        ];
    }

    /** @return list<array{key: string, label: string, type: ?string, gl_account: ?string, suggestion: ?string, orphaned: bool}> */
    private function categoryRows(Connection $connection): array
    {
        $mapped = array_diff_key(
            $connection->metadata['accounting_mapping']['gl_accounts'] ?? [],
            array_flip(self::NON_CATEGORY_GL_KEYS),
        );

        $glLabels = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_GL)
            ->pluck('label', 'code')
            ->sortKeys(SORT_NATURAL);

        $rows = [];

        foreach ($connection->account->accounting_categories ?? [] as $category) {
            $glAccount = $mapped[$category['key']] ?? null;
            unset($mapped[$category['key']]);

            $rows[] = [
                'key' => $category['key'],
                'label' => $category['label'],
                'type' => $category['type'],
                'gl_account' => $glAccount,
                'suggestion' => $glAccount === null ? $this->suggestGlAccount($category['label'], $glLabels) : null,
                'orphaned' => false,
            ];
        }

        foreach ($mapped as $key => $glAccount) {
            $rows[] = ['key' => (string) $key, 'label' => (string) $key, 'type' => null, 'gl_account' => $glAccount, 'suggestion' => null, 'orphaned' => true];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['gl_account'] !== null) <=> ($b['gl_account'] !== null));

        return $rows;
    }

    /** @param  Collection<array-key, ?string>  $glLabels */
    private function suggestGlAccount(string $label, Collection $glLabels): ?string
    {
        $words = $this->matchWords($label);
        $best = null;
        $bestScore = 0;

        foreach ($glLabels as $code => $glLabel) {
            $score = count(array_intersect($words, $this->matchWords((string) $glLabel)));

            if ($score > $bestScore) {
                $best = (string) $code;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function matchWords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => strlen($word) >= 3)));
    }

    /** @return list<array{key: string, label: string, value: ?string}> */
    private function vatRows(Connection $connection): array
    {
        $mapping = $connection->metadata['accounting_mapping']['vat_codes'] ?? [];
        $rows = [];

        foreach (self::VAT_KEYS as $key => $label) {
            $rows[] = ['key' => (string) $key, 'label' => $label, 'value' => $mapping[$key] ?? null];
        }

        return $rows;
    }

    /** @return list<array{code: string, label: string}> */
    private function refOptions(Connection $connection, string $kind): array
    {
        return ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', $kind)
            ->orderBy('code')
            ->get()
            ->map(fn (ConnectionAccountingRef $ref): array => [
                'code' => $ref->code,
                'label' => $ref->label !== null && $ref->label !== '' ? "{$ref->code} · {$ref->label}" : $ref->code,
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function mirrorCodes(Connection $connection, string $kind): array
    {
        return ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', $kind)
            ->pluck('code')
            ->all();
    }

    private function resolveConnection(Account $account, string $provider): Connection
    {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404);
        abort_unless($this->registry->supports($providerEnum->value), 404);

        /** @var Connection|null $connection */
        $connection = $account->connections()
            ->where('provider', $providerEnum->value)
            ->whereNull('revoked_at')
            ->first();

        abort_if($connection === null, 404);

        return $connection;
    }

    private function authorizeRelationRef(Connection $connection, ConnectionAccountingRef $ref): void
    {
        abort_unless(
            $ref->connection_id === $connection->getKey() && $ref->kind === ConnectionAccountingRef::KIND_RELATION,
            404,
        );
    }
}
