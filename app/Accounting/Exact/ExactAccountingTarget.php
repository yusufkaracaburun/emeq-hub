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
 * NB: de exacte body-fidelity (verplichte velden zoals datum, debet/credit-teken
 * bij journaalposten) wordt geverifieerd bij de eerste live-write (fase 2, ná de
 * Data & Security-review). De endpoints + line-velden zijn gegrond op de officiële
 * REST API-referentie (HlpRestAPIResources).
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
                '/salesinvoice/SalesInvoices',
                $this->salesInvoiceBody($document, $connection),
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
     * @return array<string, mixed>
     */
    private function salesInvoiceBody(FinancialDocument $document, Connection $connection): array
    {
        return [
            'OrderedBy' => $this->references->relationGuid($document->party, $connection),
            'Journal' => $this->references->journal($document->type, $connection),
            'InvoiceDate' => $document->issueDate->format('Y-m-d'),
            'YourRef' => $document->reference ?? $document->number,
            'Description' => $document->number ?? $document->externalId,
            'SalesInvoiceLines' => array_map(
                fn (FinancialDocumentLine $line): array => array_filter([
                    'Description' => $line->description,
                    'Quantity' => $line->quantity,
                    'UnitPrice' => $line->unitPrice,
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
        return [
            'Journal' => $this->references->journal($document->type, $connection),
            'Description' => $document->number ?? $document->externalId,
            'GeneralJournalEntryLines' => array_map(
                fn (FinancialDocumentLine $line): array => array_filter([
                    'Description' => $line->description,
                    'AmountDC' => $line->netAmount(),
                    'VATCode' => $this->references->vatCode($line->taxRate, $connection),
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

        $id = data_get($json, 'd.ID') ?? data_get($json, 'd.0.ID') ?? data_get($json, 'd.results.0.ID');

        return $id !== null ? (string) $id : null;
    }
}
