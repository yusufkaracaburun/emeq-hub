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
     * Let op bij uitbreiden: een veld zonder regel overleeft `validated()` niet en bereikt
     * de inspector dus nooit. `issue_date` stond eerst niet in deze lijst, waarna de
     * CompletenessValidator het als ontbrekend meldde terwijl het in de body zat.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string'],

            // Factuurdatum. Bewust zonder vormregel: een draft mag hier de rauwe
            // OCR-waarde dragen, en het oordeel erover komt uit de findings.
            'issue_date' => ['nullable'],

            'subtotal' => ['nullable', 'numeric'],
            'tax_total' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],

            'party' => ['nullable', 'array'],
            'lines' => ['nullable', 'array'],
        ];
    }
}
