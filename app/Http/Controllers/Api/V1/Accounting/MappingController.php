<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Enums\Capability;
use App\Http\Concerns\ResolvesAccountingConnection;
use App\Http\Controllers\Controller;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consumer-facing beheer van de boekhoud-koppeling: sync de referentie-mirror,
 * lees de beschikbare codes (`/meta`-analoog), en lees/overschrijf de optionele
 * mapping-override. Standaard hoeft de consumer hier niets te doen — de Hub synct
 * bij connect en auto-mapt; deze endpoints zijn voor expliciete (her)sync + verfijning.
 */
#[Group(name: 'Accounting Sync', description: 'Beheer de boekhoud-referentie-mirror en de optionele mapping-override van een Account-koppeling.', weight: 51)]
class MappingController extends Controller
{
    use ResolvesAccountingConnection;

    public function __construct(private readonly AccountingTargetRegistry $registry) {}

    /**
     * (Her)synchroniseer de referentiedata (grootboek/BTW/dagboeken) naar de mirror.
     */
    public function sync(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, write: true);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [, $connection] = $resolved;

        try {
            $sync = $this->registry->syncsReferenceData($connection);
        } catch (ProviderDisabledException $e) {
            return response()->json(['error' => 'provider_disabled', 'message' => $e->getMessage()], 503);
        }

        if ($sync === null) {
            return response()->json(['error' => 'sync_unsupported', 'message' => "Sync nog niet ondersteund voor provider '{$connection->provider->value}'."], 422);
        }

        return response()->json([
            'provider' => $connection->provider->value,
            'synced' => $sync->syncReferences($connection),
        ]);
    }

    /**
     * Wat de gekoppelde boekhoudprovider ondersteunt.
     *
     * `capabilities` is een platte lijst, geen object van booleans: een capability
     * toevoegen is dan additief voor consumers, terwijl `capabilities.foo === undefined`
     * versus `false` een bugfabriek is. `enabled` is de losse as — een uitgeschakelde
     * provider declareert nog steeds wat hij kan.
     */
    public function capabilities(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, write: false);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [, $connection] = $resolved;

        return response()->json([
            'provider' => $connection->provider->value,
            'enabled' => $this->registry->enabled($connection->provider->value),
            'capabilities' => array_map(
                static fn (Capability $capability): string => $capability->value,
                $this->registry->capabilitiesFor($connection),
            ),
        ]);
    }

    /**
     * De beschikbare referentie-codes uit de mirror — waaruit een override gekozen kan worden.
     */
    public function referenceData(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, write: false);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [, $connection] = $resolved;

        $refs = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->whereIn('kind', [ConnectionAccountingRef::KIND_GL, ConnectionAccountingRef::KIND_VAT, ConnectionAccountingRef::KIND_JOURNAL])
            ->orderBy('code')
            ->get()
            ->groupBy('kind')
            ->map(fn ($rows) => $rows->map(fn (ConnectionAccountingRef $r) => [
                'code' => $r->code,
                'label' => $r->label,
                'attrs' => $r->attrs,
            ])->values());

        return response()->json([
            'gl' => $refs[ConnectionAccountingRef::KIND_GL] ?? [],
            'vat' => $refs[ConnectionAccountingRef::KIND_VAT] ?? [],
            'journal' => $refs[ConnectionAccountingRef::KIND_JOURNAL] ?? [],
        ]);
    }

    /**
     * De huidige (auto-derived of overschreven) mapping van de koppeling.
     */
    public function show(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, write: false);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [, $connection] = $resolved;

        return response()->json(['mapping' => $connection->metadata['accounting_mapping'] ?? new \stdClass]);
    }

    /**
     * Overschrijf (merge) de mapping — voor wie de auto-default wil verfijnen.
     */
    public function update(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, write: true);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [, $connection] = $resolved;

        $validated = $request->validate([
            'vat_codes' => ['array'],
            'vat_codes.*' => ['string'],
            'gl_accounts' => ['array'],
            'gl_accounts.*' => ['string'],
            'journals' => ['array'],
            'journals.*' => ['string'],
            'auto_create_relations' => ['boolean'],
        ]);

        $metadata = $connection->metadata ?? [];
        $mapping = $metadata['accounting_mapping'] ?? [];

        foreach (['vat_codes', 'gl_accounts', 'journals'] as $section) {
            if (isset($validated[$section])) {
                $mapping[$section] = array_merge($mapping[$section] ?? [], $validated[$section]);
            }
        }

        // Opt-in voor relatie-auto-create — deelt de key met de admin-toggle (laatste schrijver wint).
        if (array_key_exists('auto_create_relations', $validated)) {
            $mapping['auto_create_relations'] = (bool) $validated['auto_create_relations'];
        }

        $metadata['accounting_mapping'] = $mapping;
        $connection->metadata = $metadata;
        $connection->save();

        return response()->json(['mapping' => $mapping]);
    }

    /**
     * Bearer-PAT → Consumer → Account (X-Account-Id) → actieve boekhoud-Connection.
     * Spiegelt DocumentsController; write-ops eisen `{provider}:write`, reads `:read`/`:write`.
     *
     * @return array{0: Account, 1: Connection}|JsonResponse
     */
    private function resolve(Request $request, bool $write): array|JsonResponse
    {
        [$account, $connection] = $this->resolveAccountingConnection($request, $this->registry->providers());

        $provider = $connection->provider->value;

        foreach (TokenAbilities::accounting($provider, $write) as $ability) {
            if ($request->user()?->tokenCan($ability)) {
                return [$account, $connection];
            }
        }

        $required = $write ? TokenAbilities::ACCOUNTING_WRITE : TokenAbilities::ACCOUNTING_READ;

        return response()->json(['error' => 'insufficient_ability', 'message' => "Token mist vereiste ability '{$required}'."], 403);
    }
}
