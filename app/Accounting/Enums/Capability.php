<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Contracts\EnrichesValidation;
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
    case WriteDocuments = 'documents.write';

    /** Bijlagen meesturen bij een boeking. */
    case UploadAttachments = 'documents.attachments';

    /** Referentiedata spiegelen en de default-mapping afleiden. */
    case SyncReferenceData = 'references.sync';

    /** Provider-specifieke findings toevoegen aan een dry-run. */
    case EnrichValidation = 'validation.enrich';

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
