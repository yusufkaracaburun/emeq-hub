<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Rules\ValidIban;

final class IbanValidator implements DocumentValidator
{
    public function validate(array $payload): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];
        $raw = $party['iban'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $normalized = ValidIban::normalize($raw);

        if (! ValidIban::isValid($normalized)) {
            return [new Finding(
                code: 'iban.checksum_invalid',
                severity: Severity::Error,
                blocking: true,
                path: 'party.iban',
                message: 'Het rekeningnummer (IBAN) is ongeldig. Controleer het nummer op de factuur.',
                current: $raw,
                suggestion: null,
            )];
        }

        if ($normalized !== $raw) {
            return [new Finding(
                code: 'iban.normalize',
                severity: Severity::Info,
                blocking: false,
                path: 'party.iban',
                message: 'Het rekeningnummer klopt, maar staat met spaties of kleine letters. Wij stellen de nette schrijfwijze voor.',
                current: $raw,
                suggestion: $normalized,
            )];
        }

        return [];
    }
}
