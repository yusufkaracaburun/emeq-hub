<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\Attachment;
use App\Accounting\BankStatement;
use App\Accounting\BankStatementLine;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Contracts\ConfirmsPostedDocuments;
use App\Accounting\Contracts\EnrichesValidation;
use App\Accounting\Contracts\ProbesPostedDocuments;
use App\Accounting\Contracts\ReadsBankStatements;
use App\Accounting\Contracts\ReadsDocuments;
use App\Accounting\Contracts\ReadsLedgerAccounts;
use App\Accounting\Contracts\ReadsRelations;
use App\Accounting\Contracts\ReadsTaxCodes;
use App\Accounting\Contracts\ReferenceResolver;
use App\Accounting\Contracts\SyncsReferenceData;
use App\Accounting\Contracts\UploadsAttachments;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\FinancialDocument;
use App\Accounting\FinancialDocumentLine;
use App\Accounting\LedgerAccount;
use App\Accounting\MirrorReader;
use App\Accounting\PostedDocument;
use App\Accounting\PostedDocumentLine;
use App\Accounting\Read\Cursor;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Accounting\Relation;
use App\Accounting\TaxCode;
use App\Accounting\Validation\Finding;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\ProviderEntityLink;
use Carbon\CarbonImmutable;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Enums\ExactDocumentType;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\ExactConnector;
use Emeq\ExactApi\Http\Request\Read\GetBankEntries;
use Emeq\ExactApi\Http\Request\Read\GetCashEntries;
use Emeq\ExactApi\Http\Request\Read\GetPurchaseEntries;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Read\GetSalesEntries;
use Emeq\ExactApi\Http\Request\Write\CreateDocument;
use Emeq\ExactApi\Http\Request\Write\CreateDocumentAttachment;
use Emeq\ExactApi\Http\Request\Write\CreatePurchaseEntry;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Emeq\ExactApi\OData\Envelope;
use Emeq\ExactApi\OData\Filter;
use Emeq\ExactApi\OData\Guid;
use Saloon\Http\Request as SdkRequest;
use Throwable;

final class ExactAccountingTarget implements AccountingTarget, ConfirmsPostedDocuments, EnrichesValidation, ProbesPostedDocuments, ReadsBankStatements, ReadsDocuments, ReadsLedgerAccounts, ReadsRelations, ReadsTaxCodes, SyncsReferenceData, UploadsAttachments
{
    private const YOUR_REF_MAX = 30;

    public function __construct(
        private readonly ReferenceResolver $references,
        private readonly ExactReferenceSync $referenceSync,
        private readonly ExactMappingDeriver $mappingDeriver,
        private readonly ExactReportEnricher $reportEnricher,
        private readonly MirrorReader $mirror,
    ) {}

    /** @return ReadPage<LedgerAccount> */
    public function readLedgerAccounts(Connection $connection, ReadQuery $query): ReadPage
    {
        return $this->mirror->ledgerAccounts($connection, $query);
    }

    /** @return ReadPage<TaxCode> */
    public function readTaxCodes(Connection $connection, ReadQuery $query): ReadPage
    {
        return $this->mirror->taxCodes($connection, $query);
    }

    /** @return ReadPage<Relation> */
    public function readRelations(Connection $connection, ReadQuery $query, ?string $role = null): ReadPage
    {
        $params = ['$select' => 'ID,Code,Name,VATNumber,Email,IsSales,IsSupplier'];

        if ($role !== null) {
            $params['$filter'] = Filter::eq($role === Relation::ROLE_CREDITOR ? 'IsSupplier' : 'IsSales', true)->expression;
        }

        return $this->readPage(
            $connection,
            $query,
            $params,
            static fn (array $p): SdkRequest => new GetRelations($p),
            static fn (array $rows): array => array_map(self::toRelation(...), $rows),
        );
    }

    /** @return ReadPage<PostedDocument> */
    public function readDocuments(Connection $connection, ReadQuery $query, DocumentType $type): ReadPage
    {
        $purchase = in_array($type, [DocumentType::PurchaseInvoice, DocumentType::Expense], true);

        $collection = $purchase ? 'PurchaseEntryLines' : 'SalesEntryLines';
        $partyField = $purchase ? 'Supplier' : 'Customer';

        return $this->readPage(
            $connection,
            $query,
            [
                '$select' => "EntryID,EntryNumber,{$partyField},EntryDate,DueDate,Journal,Description,YourRef,Currency",
                '$expand' => $collection,
            ],
            static fn (array $p): SdkRequest => $purchase ? new GetPurchaseEntries($p) : new GetSalesEntries($p),
            fn (array $rows): array => $this->toPostedDocuments($rows, $connection, $purchase, $collection, $partyField),
        );
    }

    public function postedDocumentStillExists(
        FinancialDocument $document,
        string $providerEntityId,
        Connection $connection,
    ): ?bool {
        $entryId = Guid::tryFrom($providerEntityId);

        if ($entryId === null) {
            return null;
        }

        $purchase = in_array($document->type, [DocumentType::PurchaseInvoice, DocumentType::Expense], true);

        $params = [
            '$select' => 'EntryID',
            '$filter' => Filter::eq('EntryID', $entryId)->expression,
            '$top' => 1,
        ];

        $response = $this->connector($connection)->send(
            $purchase ? new GetPurchaseEntries($params) : new GetSalesEntries($params),
        );

        if ($response->failed()) {
            return null;
        }

        return Envelope::results((array) $response->json()) !== [];
    }

    public function findPostedDocument(FinancialDocument $document, Connection $connection): ?PostedDocument
    {
        $key = $this->provenanceWithKey($document, $connection);

        if ($key === null) {
            return null;
        }

        $purchase = in_array($document->type, [DocumentType::PurchaseInvoice, DocumentType::Expense], true);
        $collection = $purchase ? 'PurchaseEntryLines' : 'SalesEntryLines';
        $partyField = $purchase ? 'Supplier' : 'Customer';

        $params = [
            '$select' => "EntryID,EntryNumber,{$partyField},EntryDate,DueDate,Journal,Description,YourRef,Currency",
            '$expand' => $collection,
            '$filter' => Filter::eq('YourRef', $key)->expression,
            '$top' => 1,
        ];

        $request = $purchase ? new GetPurchaseEntries($params) : new GetSalesEntries($params);
        $response = $this->connector($connection)->send($request);

        if ($response->failed()) {
            return null;
        }

        $rows = Envelope::results((array) $response->json());

        if ($rows === []) {
            return null;
        }

        return $this->toPostedDocuments($rows, $connection, $purchase, $collection, $partyField)[0] ?? null;
    }

    /** @return ReadPage<BankStatement> */
    public function readBankStatements(Connection $connection, ReadQuery $query, string $kind = BankStatement::KIND_BANK): ReadPage
    {
        $cash = $kind === BankStatement::KIND_CASH;
        $collection = $cash ? 'CashEntryLines' : 'BankEntryLines';

        return $this->readPage(
            $connection,
            $query,
            [
                '$select' => 'EntryID,EntryNumber,JournalCode,FinancialYear,FinancialPeriod,Currency,OpeningBalanceFC,ClosingBalanceFC',
                '$expand' => $collection,
            ],
            static fn (array $p): SdkRequest => $cash ? new GetCashEntries($p) : new GetBankEntries($p),
            static fn (array $rows): array => array_map(
                static fn (array $row): BankStatement => self::toBankStatement($row, $kind, $collection),
                $rows,
            ),
        );
    }

    /** @param  array<string, mixed>  $row */
    private static function toBankStatement(array $row, string $kind, string $collection): BankStatement
    {
        return new BankStatement(
            id: (string) ($row['EntryID'] ?? ''),
            kind: $kind,
            lines: self::toBankStatementLines($row[$collection] ?? null),
            number: self::nullableString($row['EntryNumber'] ?? null),
            journal: self::nullableString($row['JournalCode'] ?? null),
            financialYear: isset($row['FinancialYear']) ? (int) $row['FinancialYear'] : null,
            financialPeriod: isset($row['FinancialPeriod']) ? (int) $row['FinancialPeriod'] : null,
            openingBalance: isset($row['OpeningBalanceFC']) ? (float) $row['OpeningBalanceFC'] : null,
            closingBalance: isset($row['ClosingBalanceFC']) ? (float) $row['ClosingBalanceFC'] : null,
            currency: self::nullableString($row['Currency'] ?? null) ?? 'EUR',
        );
    }

    /** @return list<BankStatementLine> */
    private static function toBankStatementLines(mixed $raw): array
    {
        $rows = Envelope::results(is_array($raw) ? ['d' => $raw] : null);

        return array_map(static fn (array $line): BankStatementLine => new BankStatementLine(
            id: (string) ($line['ID'] ?? ''),
            amount: (float) ($line['AmountFC'] ?? 0),
            date: self::toDate($line['Date'] ?? null),
            description: self::nullableString($line['Description'] ?? null),
            relationId: self::nullableString($line['Account'] ?? null),
            relationName: self::nullableString($line['AccountName'] ?? null),
            ledgerAccountId: self::nullableString($line['GLAccount'] ?? null),
            ledgerAccountCode: self::nullableString($line['GLAccountCode'] ?? null),
            taxCode: self::nullableString($line['VATCode'] ?? null),
            documentNumber: self::nullableString($line['DocumentNumber'] ?? null),
        ), $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<PostedDocument>
     */
    private function toPostedDocuments(array $rows, Connection $connection, bool $purchase, string $collection, string $partyField): array
    {
        $names = $this->relationNames($connection, array_values(array_filter(array_map(
            static fn (array $row): ?string => self::nullableString($row[$partyField] ?? null),
            $rows,
        ))));

        $externalIds = $this->externalIdsByEntryId($connection, array_values(array_filter(array_map(
            static fn (array $row): ?string => self::nullableString($row['EntryID'] ?? null),
            $rows,
        ))));

        return array_map(function (array $row) use ($names, $externalIds, $purchase, $collection, $partyField): PostedDocument {
            $partyId = self::nullableString($row[$partyField] ?? null);
            $entryId = self::nullableString($row['EntryID'] ?? null);

            return new PostedDocument(
                id: (string) ($row['EntryID'] ?? ''),
                type: $purchase ? DocumentType::PurchaseInvoice : DocumentType::SalesInvoice,
                lines: self::toPostedLines($row[$collection] ?? null),
                number: self::nullableString($row['EntryNumber'] ?? null),
                externalId: ($entryId === null ? null : ($externalIds[$entryId] ?? null))
                    ?? self::externalIdFromProvenance(self::nullableString($row['YourRef'] ?? null)),
                issueDate: self::toDate($row['EntryDate'] ?? null),
                dueDate: self::toDate($row['DueDate'] ?? null),
                reference: self::nullableString($row['Description'] ?? null),
                partyId: $partyId,
                partyName: $partyId === null ? null : ($names[$partyId] ?? null),
                journal: self::nullableString($row['Journal'] ?? null),
                currency: self::nullableString($row['Currency'] ?? null) ?? 'EUR',
            );
        }, $rows);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function relationNames(Connection $connection, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->whereIn('native_id', array_unique($ids))
            ->pluck('label', 'native_id')
            ->filter()
            ->all();
    }

    /**
     * @param  list<string>  $entryIds
     * @return array<string, string>
     */
    private function externalIdsByEntryId(Connection $connection, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        return ProviderEntityLink::query()
            ->where('connection_id', $connection->getKey())
            ->where('entity_type', ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT)
            ->whereIn('provider_entity_id', array_unique($entryIds))
            ->pluck('external_id', 'provider_entity_id')
            ->all();
    }

    /** @return list<PostedDocumentLine> */
    private static function toPostedLines(mixed $raw): array
    {
        $rows = Envelope::results(is_array($raw) ? ['d' => $raw] : null);

        return array_map(static fn (array $line): PostedDocumentLine => new PostedDocumentLine(
            amount: (float) ($line['AmountFC'] ?? 0),
            description: self::nullableString($line['Description'] ?? null),
            taxCode: self::nullableString($line['VATCode'] ?? null),
            ledgerAccountId: self::nullableString($line['GLAccount'] ?? null),
            costCenter: self::nullableString($line['CostCenter'] ?? null),
            costUnit: self::nullableString($line['CostUnit'] ?? null),
        ), $rows);
    }

    private static function externalIdFromProvenance(?string $yourRef): ?string
    {
        if ($yourRef === null || ! str_contains($yourRef, ' · ')) {
            return null;
        }

        return self::nullableString(mb_substr($yourRef, mb_strpos($yourRef, ' · ') + 3));
    }

    private static function toDate(mixed $value): ?CarbonImmutable
    {
        $value = self::nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $row */
    private static function toRelation(array $row): Relation
    {
        $code = trim((string) ($row['Code'] ?? ''));

        return new Relation(
            id: (string) ($row['ID'] ?? ''),
            name: (string) ($row['Name'] ?? ''),
            roles: array_values(array_filter([
                ($row['IsSales'] ?? false) ? Relation::ROLE_DEBTOR : null,
                ($row['IsSupplier'] ?? false) ? Relation::ROLE_CREDITOR : null,
            ])),
            code: $code !== '' ? $code : null,
            vatNumber: self::nullableString($row['VATNumber'] ?? null),
            email: self::nullableString($row['Email'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @return string de division van de Connection
     *
     * @throws AccountingMappingException wanneer de Connection geen division heeft
     */
    private function bindSdkFor(Connection $connection): string
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

        return $division;
    }

    /**
     * @template T
     *
     * @param  array<string, scalar|null>  $params
     * @param  callable(array<string, scalar|null>): SdkRequest  $makeRequest
     * @param  callable(list<array<string, mixed>>): list<T>  $mapRows
     * @return ReadPage<T>
     */
    private function readPage(
        Connection $connection,
        ReadQuery $query,
        array $params,
        callable $makeRequest,
        callable $mapRows,
    ): ReadPage {
        $params['$top'] = $query->limit;

        if ($query->cursor !== null) {
            $params['$skiptoken'] = $query->cursor->value;
        }

        $response = $this->connector($connection)->send($makeRequest($params));

        if ($response->failed()) {
            $response->throw();
        }

        $json = (array) $response->json();
        $token = Envelope::nextSkipToken($json);

        return new ReadPage(
            items: $mapRows(Envelope::results($json)),
            nextCursor: $token === null ? null : Cursor::of($token),
        );
    }

    private function connector(Connection $connection): ExactConnector
    {
        $division = $this->bindSdkFor($connection);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        return $exact->connector($division);
    }

    public function syncReferences(Connection $connection): int
    {
        $mirrored = $this->referenceSync->sync($connection);
        $this->mappingDeriver->deriveAndStore($connection);

        return $mirrored;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    public function enrichValidation(array $payload, Connection $connection): array
    {
        return $this->reportEnricher->enrich($payload, $connection);
    }

    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        $division = $this->bindSdkFor($connection);

        $this->ensureMapping($connection);

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
            externalNumber: Envelope::firstEntryNumber($response->json()),
            raw: (array) $response->json(),
            attachments: $this->uploadAttachments($document, $connection, $connector, $entryId, Envelope::documentRef($response->json())),
        );
    }

    /** @return list<array{filename: string, status: string, document_ref: ?string, error: ?string}> */
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

    /** @return array{filename: string, status: string, document_ref: ?string, error: ?string} */
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
            $documentRef = $autoDocRef;

            if ($documentRef === null) {
                $docResponse = $connector->send(new CreateDocument(
                    subject: $subject,
                    type: $type,
                    account: $this->references->relationRef($document->party, $connection),
                    financialTransactionEntryId: $entryId,
                    documentDate: $document->issueDate->format('Y-m-d'),
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

    private function ensureMapping(Connection $connection): void
    {
        if (! empty($connection->metadata['accounting_mapping'])) {
            return;
        }

        $this->syncReferences($connection);
        $connection->refresh();
    }

    private function buildRequest(FinancialDocument $document, Connection $connection): SdkRequest
    {
        $entryDate = $document->issueDate->format('Y-m-d');
        $dueDate = $document->dueDate?->format('Y-m-d');
        $description = $document->reference ?? $document->number ?? $document->externalId;
        $yourRef = $this->provenance($document, $connection);

        return match ($document->type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote, DocumentType::Income => new CreateSalesEntry(
                customer: $this->references->relationRef($document->party, $connection),
                entryDate: $entryDate,
                journal: $this->references->journal($document->type, $connection),
                description: $description,
                lines: $this->lines($document, $connection),
                yourRef: $yourRef,
                dueDate: $dueDate,
            ),
            DocumentType::PurchaseInvoice, DocumentType::Expense => new CreatePurchaseEntry(
                supplier: $this->references->relationRef($document->party, $connection),
                entryDate: $entryDate,
                journal: $this->references->journal($document->type, $connection),
                description: $description,
                lines: $this->lines($document, $connection),
                yourRef: $yourRef,
                dueDate: $dueDate,
            ),
        };
    }

    private function provenance(FinancialDocument $document, Connection $connection): string
    {
        return $this->provenanceWithKey($document, $connection)
            ?? mb_substr($this->provenanceLead($document, $connection), 0, self::YOUR_REF_MAX);
    }

    private function provenanceWithKey(FinancialDocument $document, Connection $connection): ?string
    {
        $full = $this->provenanceLead($document, $connection).' · '.$document->externalId;

        return mb_strlen($full) <= self::YOUR_REF_MAX ? $full : null;
    }

    private function provenanceLead(FinancialDocument $document, Connection $connection): string
    {
        return $document->number ?? $connection->account?->consumer?->name ?? 'Emeq Hub';
    }

    /** @return list<array{description: ?string, amount: float, vatCode: ?string, glAccount: ?string, costCenter: ?string, costUnit: ?string}> */
    private function lines(FinancialDocument $document, Connection $connection): array
    {
        return array_map(
            fn (FinancialDocumentLine $line): array => [
                'description' => $line->description,
                'amount' => $line->netAmount(),
                'vatCode' => $this->references->vatCode($line->taxRate, $line->taxTreatment, $connection),
                'glAccount' => $this->references->glAccountRef($line->category, $document->type, $connection),
                'costCenter' => $this->references->costCenter($line->costCenter, $connection),
                'costUnit' => $this->references->costUnit($line->costUnit, $connection),
            ],
            $document->lines,
        );
    }
}
