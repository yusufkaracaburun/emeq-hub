<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lenient edge-validatie voor de dry-run /validate. Alleen de envelope wordt gecheckt
 * (party/lines zijn arrays); per-veldproblemen laten we bewust door, want díe vinden is
 * de taak van de DocumentInspector. Naast het canonical document accepteert de draft de
 * OCR-samenvatting (subtotal/tax_total/total/discount) zodat de bedragen te reconciliëren
 * zijn — die velden bestaan niet op het boek-contract.
 */
class ValidateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string'],

            'subtotal' => ['nullable', 'numeric'],
            'tax_total' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],

            'party' => ['nullable', 'array'],
            'lines' => ['nullable', 'array'],
        ];
    }
}
