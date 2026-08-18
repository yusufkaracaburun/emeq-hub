<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'interval' => ['required', 'string', 'regex:/^\d+\s+(day|days|week|weeks|month|months)$/'],
            'description' => ['required', 'string', 'max:255'],
            'startDate' => ['nullable', 'date'],
            'method' => ['nullable'],
            'metadata' => ['nullable'],
            'mandateId' => ['nullable', 'string'],
            'webhookUrl' => ['nullable', 'url'],
            'times' => ['nullable', 'integer', 'min:1'],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
}
