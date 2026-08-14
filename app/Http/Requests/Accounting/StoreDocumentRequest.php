<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Rules\ValidVatNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edge-validatie van het canonical FinancialDocument (Hub-conventie: dunne SDK's,
 * validatie aan de Hub-rand). De provider-specifieke mapping gebeurt daarná in de adapter.
 */
class StoreDocumentRequest extends FormRequest
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
            'type' => ['required', Rule::in(DocumentType::values())],
            'external_id' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'prices_include_tax' => ['boolean'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],

            'party' => ['required', 'array'],
            'party.role' => ['required', Rule::in(['debtor', 'creditor'])],
            'party.name' => ['required', 'string', 'max:255'],
            'party.vat_number' => ['nullable', 'string', 'max:64', new ValidVatNumber],
            'party.iban' => ['nullable', 'string', 'max:64'],
            'party.external_id' => ['nullable', 'string', 'max:255'],
            'party.create_if_missing' => ['boolean'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.tax_rate' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_treatment' => ['nullable', Rule::in(TaxTreatment::values())],
            'lines.*.category' => ['nullable', 'string', 'max:255'],
            'lines.*.cost_center' => ['nullable', 'string', 'max:255'],
            'lines.*.cost_unit' => ['nullable', 'string', 'max:255'],

            // Bijlagen: inline base64. max ~1,4M chars base64 ≈ 1MB binair (ADR < 1MB).
            // Begrensd omdat elke bijlage twee partner-calls van 30s kost: zonder
            // plafond is de request-duur onbegrensd en is de idempotency-lease
            // (config `hub.idempotency.lease_seconds`) niet te onderbouwen.
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.filename' => ['required', 'string', 'max:255'],
            'attachments.*.mime_type' => ['required', 'string', Rule::in(['application/pdf', 'image/png', 'image/jpeg'])],
            'attachments.*.content' => ['required', 'string', 'max:1400000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Zonder external_id slaat ExactRelationResolver::learn() de mirror-link over: de
            // volgende boeking leunt dan volledig op findRelation(), die bij een ambigue naam
            // zonder btw-nummer bewust null teruggeeft — een tweede aangemaakte relatie.
            if ($this->boolean('party.create_if_missing') && trim((string) $this->input('party.external_id')) === '') {
                $validator->errors()->add(
                    'party.external_id',
                    'party.external_id is verplicht wanneer party.create_if_missing = true — anders kan de Hub de aangemaakte relatie niet herkennen bij een volgende boeking.',
                );
            }
        });
    }
}
