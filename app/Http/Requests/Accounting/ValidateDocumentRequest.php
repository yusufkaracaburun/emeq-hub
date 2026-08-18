<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class ValidateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string'],

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
