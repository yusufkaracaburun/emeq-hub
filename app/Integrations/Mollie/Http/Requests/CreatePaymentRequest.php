<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'redirectUrl' => ['nullable', 'url'],
            'cancelUrl' => ['nullable', 'url'],
            'webhookUrl' => ['nullable', 'url'],
            'method' => ['nullable'],
            'metadata' => ['nullable'],
            'sequenceType' => ['nullable', 'string', 'in:oneoff,first,recurring'],
            'customerId' => ['nullable', 'string'],
            'mandateId' => ['nullable', 'string'],
            'profileId' => ['nullable', 'string'],
            'locale' => ['nullable', 'string'],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
}
