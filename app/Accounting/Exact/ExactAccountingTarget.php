<?php

declare(strict_types=1);

namespace App\Accounting\Exact;

use App\Accounting\AccountingResult;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\FinancialDocument;
use App\Accounting\FinancialDocumentLine;
use App\Models\Connection;
use App\Services\Exact\ConnectionTokenStore;
use App\Services\Exact\HubExactCredentialResolver;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Saloon\Enums\Method;

/**
 * Exact Online accounting-adapter. Mapt een canonical FinancialDocument naar de
 * Exact REST-API en schrijft het weg op de division van de Connection. Bindt de
 * Exact-SDK per-request (mirror ResolveExactAccount) zodat de reactieve token-
 * refresh tegen déze Connection loopt. Referentie-data (relatie/VATCode/GLAccount/
 * journaal) komt uit de ExactReferenceResolver-seam.
 *
 * Endpoints + verplichte header-velden zijn gegrond op de officiële REST API-
 * referentie (HlpRestAPIResources): verkoop = salesentry/SalesEntries (Customer/
 * Journal), inkoop = purchaseentry/PurchaseEntries (Supplier/Journal), memoriaal =
 * generaljournalentry/GeneralJournalEntries (JournalCode — afwijkend veld). Het
 * debet/credit-teken (AmountFC/AmountDC) wordt per type bij de live-write tegen de
 * test-administratie geverifieerd.
 */
final class ExactAccountingTarget implements AccountingTarget
{
    public function __construct(
        private readonly ExactReferenceResolver $references,
    ) {}

    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            throw new AccountingMappingException(
                'Exact-Connection heeft geen division (administratie_id) — herkoppel de Account.'
            );
        }

        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        [$endpoint, $body] = $this->map($document, $connection);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        $response = $exact->connector($division)->send(new RawExactRequest(
            method: Method::POST,
            endpoint: $endpoint,
            body: $body,
        ));

        if ($response->failed()) {
            $response->throw();
        }

        return new AccountingResult(
            status: $response->status(),
            externalRef: $this->extractId($response->json()),
            raw: (array) $response->json(),
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function map(FinancialDocument $document, Connection $connection): array
    {
        return match ($document->type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => [
                '/salesentry/SalesEntries',
                $this->salesEntryBody($document, $connection),
            ],
            DocumentType::PurchaseInvoice => [
                '/purchaseentry/PurchaseEntries',
                $this->purchaseEntryBody($document, $connection),
            ],
            DocumentType::Income, DocumentType::Expense => [
                '/generaljournalentry/GeneralJournalEntries',
                $this->generalJournalEntryBody($document, $connection),
            ],
        };
    }

    /**
     * Verkoop-BOEKING in het verkoopdagboek (salesentry), niet een item-based
     * SalesInvoice. Spiegel van purchaseEntryBody: GL-based regels, géén Item —
     * accounting-sync zet boekhoud-data in Exact, het invoicen gebeurt bij de
     * Consumer. Zie de SalesEntries-keuze-ADR.
     *
     * @return array<string, mixed>
     */
    private function salesEntryBody(FinancialDocument $document, Connection $connection): array
    {
        return [
            'Customer' => $this->references->relationGuid($document->party, $connection),
            'EntryDate' => $document->issueDate->format('Y-m-d'),
            'Journal' => $this->references->journal($document->type, $connection),
            'Description' => $document->number ?? $document->externalId,
            'SalesEntryLines' => array_map(
                fn (FinancialDocumentLine $line): array => array_filter([
                    'Description' => $line->description,
                    'AmountFC' => $line->netAmount(),
                    'VATCode' => $this->references->vatCode($line->taxRate, $connection),
                    'GLAccount' => $this->references->glAccountGuid($line->category, $connection),
                ], fn (mixed $v): bool => $v !== null),
                $document->lines,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseEntryBody(FinancialDocument $document, Connection $connection): array
    {
        return [
            'Supplier' => $this->references->relationGuid($document->party, $connection),
            'EntryDate' => $document->issueDate->format('Y-m-d'),
            'Journal' => $this->references->journal($document->type, $connection),
            'Description' => $document->number ?? $document->externalId,
            'PurchaseEntryLines' => array_map(
                fn (FinancialDocumentLine $line): array => array_filter([
                    'Description' => $line->description,
                    'AmountFC' => $line->netAmount(),
                    'VATCode' => $this->references->vatCode($line->taxRate, $connection),
                    'GLAccount' => $this->references->glAccountGuid($line->category, $connection),
                ], fn (mixed $v): bool => $v !== null),
                $document->lines,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generalJournalEntryBody(FinancialDocument $document, Connection $connection): array
    {
        // Memoriaal (GeneralJournalEntry) wijkt af van Sales/PurchaseEntries (live-
        // geverifieerd 2026-06-17): Exact weigert een header-`Description` (HTTP 400) én
        // een `VATCode` op de regel ("Niet toegestaan: Btw-code"). LET OP: een memoriaal
        // moet balanceren (debet=credit) — één canonical bedrag-regel mist de tegenrekening.
        // Volledige income/expense-boeking vergt nog een ontwerpkeuze (offset-grootboek in
        // de mapping → 2e regel, of routeren naar PurchaseEntry). Zie het hardening-plan.
        return [
            'JournalCode' => $this->references->journal($document->type, $connection),
            'GeneralJournalEntryLines' => array_map(
                fn (FinancialDocumentLine $line): array => array_filter([
                    'Description' => $line->description,
                    'AmountDC' => $line->netAmount(),
                    'GLAccount' => $this->references->glAccountGuid($line->category, $connection),
                ], fn (mixed $v): bool => $v !== null),
                $document->lines,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractId(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $id = data_get($json, 'd.EntryID')
            ?? data_get($json, 'd.ID')
            ?? data_get($json, 'd.results.0.EntryID')
            ?? data_get($json, 'd.results.0.ID')
            ?? data_get($json, 'd.0.EntryID')
            ?? data_get($json, 'd.0.ID');

        return $id !== null ? (string) $id : null;
    }
}
