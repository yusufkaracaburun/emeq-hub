<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Validators\ArithmeticValidator;
use App\Accounting\Validation\Validators\CompletenessValidator;
use App\Accounting\Validation\Validators\CurrencyValidator;
use App\Accounting\Validation\Validators\GeographyClassifier;
use App\Accounting\Validation\Validators\IbanValidator;
use App\Accounting\Validation\Validators\VatNumberValidator;
use App\Accounting\Validation\Validators\VatTreatmentValidator;

/**
 * Draait alle provider-agnostische validators over een geëxtraheerd draft-document en
 * verzamelt hun findings. Pure orchestratie — geen Connection of provider-call — zodat de
 * laag los van Exact/Snelstart te testen is. De default-set is dependency-vrij; geef een
 * eigen lijst mee om te isoleren in tests.
 */
final class DocumentInspector
{
    /** @var list<DocumentValidator> */
    private array $validators;

    /**
     * @param  list<DocumentValidator>|null  $validators
     */
    public function __construct(?array $validators = null)
    {
        $this->validators = $validators ?? self::defaults();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function inspect(array $payload): InspectionReport
    {
        $findings = [];

        foreach ($this->validators as $validator) {
            $findings = array_merge($findings, $validator->validate($payload));
        }

        return new InspectionReport(array_values($findings));
    }

    /**
     * @return list<DocumentValidator>
     */
    private static function defaults(): array
    {
        return [
            // Eerst: valt er iets te boeken? De rest oordeelt over de inhoud.
            new CompletenessValidator,
            new ArithmeticValidator,
            new IbanValidator,
            new VatNumberValidator,
            new GeographyClassifier,
            new VatTreatmentValidator,
            new CurrencyValidator,
        ];
    }
}
