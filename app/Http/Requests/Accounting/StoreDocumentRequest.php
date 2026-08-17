<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Party;
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
            'party.kind' => ['required', Rule::in([Party::KIND_COMPANY, Party::KIND_PERSON])],
            'party.name' => ['required', 'string', 'max:255'],
            'party.vat_number' => ['nullable', 'string', 'max:64', new ValidVatNumber],
            'party.iban' => ['nullable', 'string', 'max:64'],
            'party.external_id' => ['required', 'string', 'max:255'],
            'party.relation_id' => ['nullable', 'string', 'max:255'],

            // Relatiekaart — optioneel, en alleen van belang wanneer de Hub de
            // relatie aanmaakt. Lengtes volgen de Exact-velden.
            'party.chamber_of_commerce' => ['nullable', 'string', 'max:20'],
            'party.address_line_1' => ['nullable', 'string', 'max:255'],
            'party.address_line_2' => ['nullable', 'string', 'max:255'],
            'party.postcode' => ['nullable', 'string', 'max:20'],
            'party.city' => ['nullable', 'string', 'max:255'],
            'party.state' => ['nullable', 'string', 'max:64'],
            'party.country' => ['nullable', 'string', 'size:2'],
            'party.email' => ['nullable', 'email', 'max:255'],
            'party.phone' => ['nullable', 'string', 'max:64'],
            'party.website' => ['nullable', 'string', 'max:255'],

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
            // Een zakelijke partij zonder KvK én zonder btw-nummer kan de resolver alleen op
            // naam herkennen, en een naam-miss maakt een duplicaat aan in de administratie van
            // de klant. Liever hier weigeren dan daar opruimen.
            if ($this->input('party.kind') !== Party::KIND_COMPANY) {
                return;
            }

            // Met een gepinde relatie doet de ladder geen enkele zoekstap meer, dus valt de
            // reden voor de sleutel-eis weg.
            if (trim((string) $this->input('party.relation_id')) !== '') {
                return;
            }

            $hasChamber = trim((string) $this->input('party.chamber_of_commerce')) !== '';
            $hasVat = trim((string) $this->input('party.vat_number')) !== '';

            if (! $hasChamber && ! $hasVat) {
                $validator->errors()->add(
                    'party.chamber_of_commerce',
                    'Een zakelijke tegenpartij heeft een KvK-nummer of een btw-nummer nodig. Zonder een van beide kan de boekhouding de relatie niet met zekerheid herkennen. Gaat het om een particulier, stuur dan party.kind = person.',
                );
            }
        });
    }
}
