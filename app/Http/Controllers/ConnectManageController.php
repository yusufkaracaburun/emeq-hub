<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccountingTargetRegistry;
use App\Enums\Provider;
use App\Integrations\Exact\ExactReferenceData;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Support\Connect\ConnectLinkFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Beheerdrawer per koppeling op de signed connect-pagina: de payload achter de
 * drawer, de boekhoud-mapping opslaan, en relaties herkoppelen/ontkoppelen/zoeken.
 *
 * Zelfde bewijslast als {@see ConnectHandoffController}: `signed`-middleware +
 * Account-binding uit de handtekening, geen eindgebruiker-auth. Alleen zichtbaar
 * voor providers met een AccountingTarget (nu Exact) — de rest krijgt 404, net
 * als een niet-bestaande provider. Zie `.docs/decisions/connection-management-drawer.md`.
 */
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
            // De adapter schrijft `response_body` in `pass_through_calls` alleen bij een
            // fout (status >= 400, zie PassThroughCall::errorBody()); een geslaagde
            // boeking laat geen melding na om hier te tonen. Tab bestaat structureel
            // (het ontwerp), maar draagt bewust geen data tot dat een aparte beslissing
            // is — zie het sessierapport.
            'bookings' => [
                'available' => false,
            ],
            'relations' => $this->relationRows($connection, $request, $account, $provider),
            'settings' => $this->settingsPayload($connection),
            'urls' => [
                'mapping_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.mapping'),
                'relations_search_url' => $this->links->manageActionUrl($request, $account, $provider, 'connect.manage.relations.search'),
            ],
        ]);
    }

    /**
     * Bewaart alleen de vier bewerkbare sleutels; alles anders in de request (bv.
     * `vat_codes`) wordt genegeerd — `validate()` retourneert nooit meer dan de
     * geregistreerde regels. Elke waarde moet een Code uit de mirror zijn (geen
     * vrije tekst, zie ADR); leeg = de override wissen.
     */
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

    /**
     * Herkoppelen zet `native_id` op een andere relatie (typisch een kandidaat uit
     * `searchRelations`). Raakt alleen de lokale mirror-rij — geen Exact-write, dus
     * geen `ProviderEntityLink`: de klant corrigeert hier alleen waar de Hub naar
     * wijst, niet de administratie zelf.
     */
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

    /**
     * Exacte, genormaliseerde naam-match (zelfde stap als de resolutie-ladder) —
     * geen fuzzy zoeken. Dit is de dure volledige scan uit
     * {@see ExactReferenceData::relationsByName()}, dus bewust geen live-typen op
     * de front-end en een eigen throttle op de route.
     */
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

    /**
     * @return list<array<string, mixed>>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function refOptions(Connection $connection, string $kind): array
    {
        return ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', $kind)
            ->orderBy('code')
            ->get()
            ->map(fn (ConnectionAccountingRef $ref): array => [
                'code' => $ref->code,
                'label' => $ref->label !== null && $ref->label !== '' ? "{$ref->code} — {$ref->label}" : $ref->code,
            ])
            ->values()
            ->all();
    }

    private function resolveConnection(Account $account, string $provider): Connection
    {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404);
        abort_unless($this->registry->supports($providerEnum->value), 404);

        $connection = $account->connections()
            ->where('provider', $providerEnum->value)
            ->whereNull('revoked_at')
            ->first();

        abort_if($connection === null, 404);

        return $connection;
    }

    /**
     * De handtekening dekt `ref` al (een geruilde id maakt de URL ongeldig), maar
     * deze check is de expliciete Account-scoping die de rest van deze controller
     * ook toepast — geen enkele actie leunt op de handtekening alleen.
     */
    private function authorizeRelationRef(Connection $connection, ConnectionAccountingRef $ref): void
    {
        abort_unless(
            $ref->connection_id === $connection->getKey() && $ref->kind === ConnectionAccountingRef::KIND_RELATION,
            404,
        );
    }
}
