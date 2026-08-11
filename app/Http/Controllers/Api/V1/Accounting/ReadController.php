<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\BankStatement;
use App\Accounting\Contracts\ReadsBankStatements;
use App\Accounting\Contracts\ReadsDocuments;
use App\Accounting\Contracts\ReadsLedgerAccounts;
use App\Accounting\Contracts\ReadsRelations;
use App\Accounting\Contracts\ReadsTaxCodes;
use App\Accounting\Enums\Capability;
use App\Accounting\Enums\DocumentType;
use App\Accounting\LedgerAccount;
use App\Accounting\PostedDocument;
use App\Accounting\Read\ReadQuery;
use App\Accounting\Relation;
use App\Accounting\TaxCode;
use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Concerns\ResolvesAccountingConnection;
use App\Http\Controllers\Controller;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\Sanctum\TokenAbilities;
use App\Support\Exact\UpstreamErrorMapper;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Provider-onafhankelijk lezen van de gekoppelde administratie.
 *
 * De controller kent geen providernaam: hij vraagt de registry om het leescontract
 * en krijgt `null` als de gekoppelde provider het niet kan. Grootboek en btw-codes
 * komen uit de mirror (geen partner-call), relaties live.
 *
 * `/customers` en `/suppliers` zijn twee ingangen op één bron — beide partners die
 * we kennen hebben één relatie-entiteit met rolvlaggen.
 */
#[Group(name: 'Accounting Read', description: 'Lees grootboek, btw-codes en relaties van de gekoppelde boekhouding in canonieke vorm.', weight: 52)]
class ReadController extends Controller
{
    use GuardsTokenAbility;
    use ResolvesAccountingConnection;

    public function __construct(private readonly AccountingTargetRegistry $registry) {}

    /**
     * Geboekte documenten uit de gekoppelde administratie.
     *
     * De leeskant van `POST /v1/accounting/documents` — zelfde pad, zelfde canonieke
     * begrip. Filter met `?type=sales_invoice|purchase_invoice`; zonder filter komen
     * verkoopboekingen terug, want dat is wat de meeste consumers zoeken.
     */
    public function documents(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if (is_string($type) && DocumentType::tryFrom($type) === null) {
            return response()->json([
                'error' => 'invalid_query',
                'message' => "Onbekend type '{$type}'. Geldig: ".implode(', ', DocumentType::values()).'.',
            ], 400);
        }

        $documentType = is_string($type) ? DocumentType::from($type) : null;

        return $this->read(
            $request,
            Capability::ReadDocuments,
            fn (object $target, ReadQuery $query, $connection) => $target instanceof ReadsDocuments
                ? $target->readDocuments($connection, $query, $documentType)
                : null,
            static fn (PostedDocument $item): array => $item->toArray(),
        );
    }

    /**
     * Bank- of kasafschriften met hun mutaties.
     *
     * Filter met `?kind=bank|cash`; standaard bank. Dit is de resource waarover de
     * bank-webhooks notificeren — zonder dit endpoint is zo'n melding onbruikbaar.
     */
    public function bankStatements(Request $request): JsonResponse
    {
        $kind = $request->query('kind', BankStatement::KIND_BANK);

        if (! in_array($kind, [BankStatement::KIND_BANK, BankStatement::KIND_CASH], true)) {
            return response()->json([
                'error' => 'invalid_query',
                'message' => "Onbekende kind '{$kind}'. Geldig: bank, cash.",
            ], 400);
        }

        return $this->read(
            $request,
            Capability::ReadBankStatements,
            fn (object $target, ReadQuery $query, $connection) => $target instanceof ReadsBankStatements
                ? $target->readBankStatements($connection, $query, (string) $kind)
                : null,
            static fn (BankStatement $item): array => $item->toArray(),
        );
    }

    /**
     * Grootboekrekeningen van de gekoppelde administratie.
     */
    public function ledgerAccounts(Request $request): JsonResponse
    {
        return $this->read(
            $request,
            Capability::ReadLedgerAccounts,
            fn (object $target, ReadQuery $query, $connection) => $target instanceof ReadsLedgerAccounts
                ? $target->readLedgerAccounts($connection, $query)
                : null,
            static fn (LedgerAccount $item): array => $item->toArray(),
        );
    }

    /**
     * Btw-codes van de gekoppelde administratie.
     */
    public function taxCodes(Request $request): JsonResponse
    {
        return $this->read(
            $request,
            Capability::ReadTaxCodes,
            fn (object $target, ReadQuery $query, $connection) => $target instanceof ReadsTaxCodes
                ? $target->readTaxCodes($connection, $query)
                : null,
            static fn (TaxCode $item): array => $item->toArray(),
        );
    }

    /**
     * Debiteuren (klanten) van de gekoppelde administratie.
     */
    public function customers(Request $request): JsonResponse
    {
        return $this->relations($request, Relation::ROLE_DEBTOR);
    }

    /**
     * Crediteuren (leveranciers) van de gekoppelde administratie.
     */
    public function suppliers(Request $request): JsonResponse
    {
        return $this->relations($request, Relation::ROLE_CREDITOR);
    }

    private function relations(Request $request, string $role): JsonResponse
    {
        return $this->read(
            $request,
            Capability::ReadRelations,
            fn (object $target, ReadQuery $query, $connection) => $target instanceof ReadsRelations
                ? $target->readRelations($connection, $query, $role)
                : null,
            static fn (Relation $item): array => $item->toArray(),
        );
    }

    /**
     * Eén vorm voor alle lees-endpoints: connectie resolven, ability checken,
     * capability opvragen, pagineren, partner-fouten normaliseren.
     */
    private function read(Request $request, Capability $capability, callable $fetch, callable $transform): JsonResponse
    {
        [, $connection] = $this->resolveAccountingConnection($request, $this->registry->providers());

        $provider = $connection->provider->value;
        $this->guardAbility($request, TokenAbilities::accounting($provider, write: false));

        if (! in_array($capability, $this->registry->capabilitiesFor($connection), true)) {
            return response()->json([
                'error' => 'unsupported_capability',
                'message' => "Provider '{$provider}' ondersteunt '{$capability->value}' niet.",
            ], 422);
        }

        try {
            $query = ReadQuery::fromRequest($request->query());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_query', 'message' => $e->getMessage()], 400);
        }

        try {
            $page = $fetch($this->registry->for($provider), $query, $connection);
        } catch (ProviderDisabledException $e) {
            return response()->json(['error' => 'provider_disabled', 'message' => $e->getMessage()], 503);
        } catch (Exception $e) {
            // Bewust `Exception` en niet `Throwable`: een PHP-`Error` is onze bug en
            // hoort als 500 met stacktrace naar boven te komen, niet vermomd als
            // "partner onbereikbaar" — dat stuurt de zoektocht de verkeerde kant op.
            $mapped = UpstreamErrorMapper::mapException($e);

            return response()->json($mapped['body'], $mapped['status'], $mapped['headers']);
        }

        return response()->json($page->toArray($transform));
    }
}
