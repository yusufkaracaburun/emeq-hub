<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Exact\ExactMappingDeriver;
use App\Accounting\Exact\ExactReferenceSync;
use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
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

        if ($connection->provider !== Provider::Exact) {
            return response()->json(['error' => 'sync_unsupported', 'message' => "Sync nog niet ondersteund voor provider '{$connection->provider->value}'."], 422);
        }

        $count = app(ExactReferenceSync::class)->sync($connection);
        app(ExactMappingDeriver::class)->deriveAndStore($connection);

        return response()->json(['provider' => $connection->provider->value, 'synced' => $count]);
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
        $accountHeader = $request->header('X-Account-Id');

        if (! is_string($accountHeader) || $accountHeader === '') {
            return response()->json(['error' => 'missing_account_header', 'message' => 'Vereiste header X-Account-Id ontbreekt.'], 400);
        }

        $account = Account::query()
            ->where('consumer_id', $request->user()?->getKey())
            ->where('external_id', $accountHeader)
            ->first();

        if ($account === null) {
            return response()->json(['error' => 'account_not_found', 'message' => 'Account niet gevonden voor deze Consumer.'], 404);
        }

        $connection = $account->connections()
            ->whereNull('revoked_at')
            ->whereIn('provider', $this->registry->providers())
            ->first();

        if ($connection === null) {
            return response()->json(['error' => 'no_accounting_connection', 'message' => 'Geen actieve boekhoud-Connection voor dit Account.'], 404);
        }

        $provider = $connection->provider->value;
        $abilities = $write ? ["{$provider}:write"] : ["{$provider}:read", "{$provider}:write"];

        foreach ($abilities as $ability) {
            if ($request->user()?->tokenCan($ability)) {
                return [$account, $connection];
            }
        }

        return response()->json(['error' => 'insufficient_ability', 'message' => "Token mist vereiste ability '{$provider}:".($write ? 'write' : 'read')."'."], 403);
    }
}
