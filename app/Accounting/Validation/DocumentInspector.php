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

final class DocumentInspector
{
    /** @var list<DocumentValidator> */
    private array $validators;

    /** @param  list<DocumentValidator>|null  $validators */
    public function __construct(?array $validators = null)
    {
        $this->validators = $validators ?? self::defaults();
    }

    /** @param  array<string, mixed>  $payload */
    public function inspect(array $payload): InspectionReport
    {
        $findings = [];

        foreach ($this->validators as $validator) {
            $findings = array_merge($findings, $validator->validate($payload));
        }

        return new InspectionReport(array_values($findings));
    }

    /** @return list<DocumentValidator> */
    private static function defaults(): array
    {
        return [
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
