<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccountingTargetRegistry;
use App\Enums\Provider;
use App\Integrations\Exact\ExactReferenceData;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\PassThroughCall;
use App\Models\ProviderEntityLink;
use App\Support\Connect\ConnectLinkFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConnectManageController extends Controller
{
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
            'settings' => $this->settingsPayload($connection),
            'urls' => [
                'mapping_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.mapping'),
                'relations_search_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.relations.search'),
            ],
        ]);
    }

    public function updateMapping(Request $request, Account $account, string $provider): JsonResponse
    {
        $connection = $this->resolveConnection($account, $provider);

        $journalCodes = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_JOURNAL)
            ->pluck('code')
            ->all();

        $glCodes = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_GL)
            ->pluck('code')
            ->all();

        $validated = $request->validate([
            'journals.sales' => ['sometimes', 'nullable', 'string', Rule::in($journalCodes)],
            'journals.purchase' => ['sometimes', 'nullable', 'string', Rule::in($journalCodes)],
            'gl_accounts.sales_default' => ['sometimes', 'nullable', 'string', Rule::in($glCodes)],
            'gl_accounts.purchase_default' => ['sometimes', 'nullable', 'string', Rule::in($glCodes)],
        ]);

        $metadata = $connection->metadata ?? [];
        $mapping = $metadata['accounting_mapping'] ?? [];

        foreach (['journals' => ['sales', 'purchase'], 'gl_accounts' => ['sales_default', 'purchase_default']] as $section => $keys) {
            foreach ($keys as $key) {
                if (! array_key_exists($section, $validated) || ! array_key_exists($key, $validated[$section])) {
                    continue;
                }

                if ($validated[$section][$key] === null) {
                    unset($mapping[$section][$key]);
                } else {
                    $mapping[$section][$key] = $validated[$section][$key];
                }
            }
        }

        $metadata['accounting_mapping'] = $mapping;
        $connection->metadata = $metadata;
        $connection->save();

        return response()->json(['settings' => $this->settingsPayload($connection)]);
    }

    public function relinkRelation(Request $request, Account $account, string $provider, ConnectionAccountingRef $ref): JsonResponse
    {
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
    private function settingsPayload(Connection $connection): array
    {
        $mapping = $connection->metadata['accounting_mapping'] ?? [];

        return [
            'journals' => [
                'sales' => $mapping['journals']['sales'] ?? null,
                'purchase' => $mapping['journals']['purchase'] ?? null,
                'options' => $this->refOptions($connection, ConnectionAccountingRef::KIND_JOURNAL),
            ],
            'gl_accounts' => [
                'sales_default' => $mapping['gl_accounts']['sales_default'] ?? null,
                'purchase_default' => $mapping['gl_accounts']['purchase_default'] ?? null,
                'options' => $this->refOptions($connection, ConnectionAccountingRef::KIND_GL),
            ],
            'vat_codes' => $this->vatRows($connection),
        ];
    }

    /** @return list<array{label: string, value: string}> */
    private function vatRows(Connection $connection): array
    {
        $mapping = $connection->metadata['accounting_mapping']['vat_codes'] ?? [];

        if ($mapping === []) {
            return [];
        }

        $labels = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_VAT)
            ->pluck('label', 'code');

        $rows = [];

        foreach (['21' => '21%', '9' => '9%', '0' => '0%', 'reverse_charge:21' => '21% verlegd', 'reverse_charge:9' => '9% verlegd'] as $key => $label) {
            $code = $mapping[$key] ?? null;

            if ($code === null) {
                continue;
            }

            $vatLabel = (string) ($labels[$code] ?? '');
            $rows[] = ['label' => $label, 'value' => $vatLabel === '' ? $code : "{$code} · {$vatLabel}"];
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
