<?php

declare(strict_types=1);

namespace App\Accounting\Exact;

use App\Accounting\AccountingResult;
use App\Accounting\Attachment;
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
use Emeq\ExactApi\Enums\ExactDocumentType;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\ExactConnector;
use Emeq\ExactApi\Http\Request\Write\CreateDocument;
use Emeq\ExactApi\Http\Request\Write\CreateDocumentAttachment;
use Emeq\ExactApi\Http\Request\Write\CreateGeneralJournalEntry;
use Emeq\ExactApi\Http\Request\Write\CreatePurchaseEntry;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Emeq\ExactApi\OData\Envelope;
use Saloon\Http\Request as SdkRequest;
use Throwable;

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
        $connector = $exact->connector($division);

        $response = $connector->send($this->buildRequest($document, $connection));

        if ($response->failed()) {
            $response->throw();
        }

        $entryId = Envelope::firstId($response->json());

        return new AccountingResult(
            status: $response->status(),
            externalRef: $entryId,
            raw: (array) $response->json(),
            attachments: $this->uploadAttachments($document, $connection, $connector, $entryId, Envelope::documentRef($response->json())),
        );
    }

    /**
     * Uploadt elke bijlage in 2 stappen (Document → DocumentAttachment) ná de boeking.
     * Best-effort: de boeking is leidend en al persistent; een mislukte bijlage gooit
     * niet (anders herboekt een idempotency-retry) maar wordt per stuk gerapporteerd.
     *
     * @return list<array{filename: string, status: string, document_ref: ?string, error: ?string}>
     */
    private function uploadAttachments(
        FinancialDocument $document,
        Connection $connection,
        ExactConnector $connector,
        ?string $entryId,
        ?string $autoDocRef,
    ): array {
        if ($document->attachments === []) {
            return [];
        }

        $type = $this->documentTypeId($document->type);
        $subject = $document->number ?? $document->externalId;

        return array_map(
            fn (Attachment $attachment): array => $this->uploadAttachment(
                $attachment,
                $connection,
                $connector,
                $document,
                $type,
                $subject,
                $entryId,
                $autoDocRef,
            ),
            array_values($document->attachments),
        );
    }

    /**
     * @return array{filename: string, status: string, document_ref: ?string, error: ?string}
     */
    private function uploadAttachment(
        Attachment $attachment,
        Connection $connection,
        ExactConnector $connector,
        FinancialDocument $document,
        int $type,
        string $subject,
        ?string $entryId,
        ?string $autoDocRef,
    ): array {
        try {
            // Inkoop: Exact koppelt al automatisch een Document aan de boeking (`d.Document`)
            // → de bijlage dáár aan hangen, anders krijg je een dubbel document. Verkoop
            // heeft geen auto-Document → er zelf één aanmaken en koppelen.
            $documentRef = $autoDocRef;

            if ($documentRef === null) {
                $docResponse = $connector->send(new CreateDocument(
                    subject: $subject,
                    type: $type,
                    account: $this->references->relationGuid($document->party, $connection),
                    financialTransactionEntryId: $entryId,
                ));

                if ($docResponse->failed()) {
                    $docResponse->throw();
                }

                $documentRef = Envelope::firstId($docResponse->json());
            }

            $attachResponse = $connector->send(new CreateDocumentAttachment(
                document: (string) $documentRef,
                fileName: $attachment->filename,
                attachment: $attachment->content,
            ));

            if ($attachResponse->failed()) {
                $attachResponse->throw();
            }

            return [
                'filename' => $attachment->filename,
                'status' => 'uploaded',
                'document_ref' => $documentRef,
                'error' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'filename' => $attachment->filename,
                'status' => 'failed',
                'document_ref' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function documentTypeId(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => ExactDocumentType::SalesInvoice->value,
            DocumentType::PurchaseInvoice => ExactDocumentType::PurchaseInvoice->value,
            DocumentType::Income, DocumentType::Expense => ExactDocumentType::Miscellaneous->value,
        };
    }

    private function buildRequest(FinancialDocument $document, Connection $connection): SdkRequest
    {
        $entryDate = $document->issueDate->format('Y-m-d');
        $description = $document->number ?? $document->externalId;
        $yourRef = $this->provenance($document, $connection);

        return match ($document->type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => new CreateSalesEntry(
                customer: $this->references->relationGuid($document->party, $connection),
                entryDate: $entryDate,
                journal: $this->references->journal($document->type, $connection),
                description: $description,
                lines: $this->lines($document, $connection),
                yourRef: $yourRef,
            ),
            DocumentType::PurchaseInvoice => new CreatePurchaseEntry(
                supplier: $this->references->relationGuid($document->party, $connection),
                entryDate: $entryDate,
                journal: $this->references->journal($document->type, $connection),
                description: $description,
                lines: $this->lines($document, $connection),
                yourRef: $yourRef,
            ),
            DocumentType::Income, DocumentType::Expense => new CreateGeneralJournalEntry(
                journalCode: $this->references->journal($document->type, $connection),
                lines: $this->lines($document, $connection),
            ),
        };
    }

    /**
     * Herkomst-stempel voor Exact `YourRef`: "{consumer-app} · {external_id}" — zo ziet
     * de boekhouder per boeking welke consumer-app + bron-document 'm aanmaakte. Max 50
     * tekens (Exact kapt YourRef af); de consumer-naam houdt voorrang.
     */
    private function provenance(FinancialDocument $document, Connection $connection): string
    {
        $consumer = $connection->account?->consumer?->name ?? 'Emeq Hub';

        return mb_substr($consumer.' · '.$document->externalId, 0, 50);
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
