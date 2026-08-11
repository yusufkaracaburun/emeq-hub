<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Contracts\EnrichesValidation;
use App\Accounting\Contracts\ReadsBankStatements;
use App\Accounting\Contracts\ReadsDocuments;
use App\Accounting\Contracts\ReadsLedgerAccounts;
use App\Accounting\Contracts\ReadsRelations;
use App\Accounting\Contracts\ReadsTaxCodes;
use App\Accounting\Contracts\SyncsReferenceData;
use App\Accounting\Contracts\UploadsAttachments;

/**
 * Wat een accounting-adapter kan. Gesloten set: een typefout in een vrije string
 * faalt pas in productie, in een 422-pad dat je zelden raakt.
 *
 * Elke case wijst naar draaiende code. Een capability is aanwezig dan en slechts dan
 * als de geregistreerde adapter het bijbehorende contract implementeert — declaratie
 * en gedrag kunnen dus niet uit elkaar lopen, want het is hetzelfde feit. Vandaar
 * géén capability-lijst in config: config kan liegen tegen de code, `implements` niet.
 */
enum Capability: string
{
    /** Een canoniek document boeken. */
    case WriteDocuments = 'accounting.documents.write';

    /** Bijlagen meesturen bij een boeking. */
    case UploadAttachments = 'accounting.documents.attachments';

    /** Referentiedata spiegelen en de default-mapping afleiden. */
    case SyncReferenceData = 'accounting.references.sync';

    /** Provider-specifieke findings toevoegen aan een dry-run. */
    case EnrichValidation = 'accounting.validation.enrich';

    /** Bank- en kasafschriften uitlezen. */
    case ReadBankStatements = 'accounting.bank_statements.read';

    /** Geboekte documenten uitlezen. */
    case ReadDocuments = 'accounting.documents.read';

    /** Grootboekrekeningen uitlezen. */
    case ReadLedgerAccounts = 'accounting.ledger_accounts.read';

    /** Btw-codes uitlezen. */
    case ReadTaxCodes = 'accounting.tax_codes.read';

    /** Debiteuren en crediteuren uitlezen. */
    case ReadRelations = 'accounting.relations.read';

    /**
     * Het contract dat deze capability waarmaakt.
     *
     * @return class-string
     */
    public function contract(): string
    {
        return match ($this) {
            self::WriteDocuments => AccountingTarget::class,
            self::UploadAttachments => UploadsAttachments::class,
            self::SyncReferenceData => SyncsReferenceData::class,
            self::EnrichValidation => EnrichesValidation::class,
            self::ReadBankStatements => ReadsBankStatements::class,
            self::ReadDocuments => ReadsDocuments::class,
            self::ReadLedgerAccounts => ReadsLedgerAccounts::class,
            self::ReadTaxCodes => ReadsTaxCodes::class,
            self::ReadRelations => ReadsRelations::class,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
