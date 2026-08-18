<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Contracts\EnrichesValidation;
use App\Accounting\Contracts\ProbesPostedDocuments;
use App\Accounting\Contracts\ReadsBankStatements;
use App\Accounting\Contracts\ReadsDocuments;
use App\Accounting\Contracts\ReadsLedgerAccounts;
use App\Accounting\Contracts\ReadsRelations;
use App\Accounting\Contracts\ReadsTaxCodes;
use App\Accounting\Contracts\SyncsReferenceData;
use App\Accounting\Contracts\UploadsAttachments;

enum Capability: string
{
    case WriteDocuments = 'accounting.documents.write';

    case UploadAttachments = 'accounting.documents.attachments';

    case SyncReferenceData = 'accounting.references.sync';

    case EnrichValidation = 'accounting.validation.enrich';

    case ProbeDocuments = 'accounting.documents.probe';

    case ReadBankStatements = 'accounting.bank_statements.read';

    case ReadDocuments = 'accounting.documents.read';

    case ReadLedgerAccounts = 'accounting.ledger_accounts.read';

    case ReadTaxCodes = 'accounting.tax_codes.read';

    case ReadRelations = 'accounting.relations.read';

    /** @return class-string */
    public function contract(): string
    {
        return match ($this) {
            self::WriteDocuments => AccountingTarget::class,
            self::UploadAttachments => UploadsAttachments::class,
            self::SyncReferenceData => SyncsReferenceData::class,
            self::EnrichValidation => EnrichesValidation::class,
            self::ProbeDocuments => ProbesPostedDocuments::class,
            self::ReadBankStatements => ReadsBankStatements::class,
            self::ReadDocuments => ReadsDocuments::class,
            self::ReadLedgerAccounts => ReadsLedgerAccounts::class,
            self::ReadTaxCodes => ReadsTaxCodes::class,
            self::ReadRelations => ReadsRelations::class,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
