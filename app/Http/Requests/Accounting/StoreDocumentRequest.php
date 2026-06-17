<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Accounting\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'party.vat_number' => ['nullable', 'string', 'max:64'],
            'party.iban' => ['nullable', 'string', 'max:64'],
            'party.external_id' => ['nullable', 'string', 'max:255'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.tax_rate' => ['required', 'numeric', 'min:0'],
            'lines.*.category' => ['nullable', 'string', 'max:255'],
        ];
    }
}
