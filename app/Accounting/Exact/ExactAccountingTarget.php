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
use Emeq\ExactApi\Http\Request\Write\CreateGeneralJournalEntry;
use Emeq\ExactApi\Http\Request\Write\CreatePurchaseEntry;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Emeq\ExactApi\OData\Envelope;
use Saloon\Http\Request as SdkRequest;

/**
 * Exact Online accounting-adapter. Mapt een canonical FinancialDocument op de juiste
 * emeq/exact-api write-request en schrijft die weg op de division van de Connection.
 * Bindt de Exact-SDK per-request (mirror ResolveExactAccount) zodat de reactieve
 * token-refresh tegen déze Connection loopt. Referentie-data (relatie/VATCode/
 * GLAccount/journaal) komt uit de ExactReferenceResolver-seam.
 *
 * De Exact-wire (endpoints, veldnamen, AmountFC/AmountDC, response-envelope) leeft in
 * de SDK; deze adapter levert alleen geresolvede waarden in een neutrale regel-vorm.
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

        /** @var Exact $exact */
        $exact = app(Exact::class);

        $response = $exact->connector($division)->send($this->buildRequest($document, $connection));

        if ($response->failed()) {
            $response->throw();
        }

        return new AccountingResult(
            status: $response->status(),
            externalRef: Envelope::firstId($response->json()),
            raw: (array) $response->json(),
        );
    }

    private function buildRequest(FinancialDocument $document, Connection $connection): SdkRequest
    {
        $entryDate = $document->issueDate->format('Y-m-d');
        $description = $document->number ?? $document->externalId;

        return match ($document->type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => new CreateSalesEntry(
                customer: $this->references->relationGuid($document->party, $connection),
                entryDate: $entryDate,
                journal: $this->references->journal($document->type, $connection),
                description: $description,
                lines: $this->lines($document, $connection),
            ),
            DocumentType::PurchaseInvoice => new CreatePurchaseEntry(
                supplier: $this->references->relationGuid($document->party, $connection),
                entryDate: $entryDate,
                journal: $this->references->journal($document->type, $connection),
                description: $description,
                lines: $this->lines($document, $connection),
            ),
            DocumentType::Income, DocumentType::Expense => new CreateGeneralJournalEntry(
                journalCode: $this->references->journal($document->type, $connection),
                lines: $this->lines($document, $connection),
            ),
        };
    }

    /**
     * Geresolvede regels in de neutrale vorm die de SDK-write-requests verwachten.
     *
     * @return list<array{description: ?string, amount: float, vatCode: ?string, glAccount: ?string}>
     */
    private function lines(FinancialDocument $document, Connection $connection): array
    {
        return array_map(
            fn (FinancialDocumentLine $line): array => [
                'description' => $line->description,
                'amount' => $line->netAmount(),
                'vatCode' => $this->references->vatCode($line->taxRate, $connection),
                'glAccount' => $this->references->glAccountGuid($line->category, $connection),
            ],
            $document->lines,
        );
    }
}
