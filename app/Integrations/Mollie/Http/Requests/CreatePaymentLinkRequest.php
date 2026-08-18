<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentLinkRequest extends FormRequest
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
            'amount' => ['nullable', 'array'],
            'amount.currency' => ['required_with:amount', 'string', 'size:3'],
            'amount.value' => ['required_with:amount', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'minimumAmount' => ['nullable', 'array'],
            'minimumAmount.currency' => ['required_with:minimumAmount', 'string', 'size:3'],
            'minimumAmount.value' => ['required_with:minimumAmount', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'redirectUrl' => ['nullable', 'url'],
            'webhookUrl' => ['nullable', 'url'],
            'expiresAt' => ['nullable', 'date'],
            'allowedMethods' => ['nullable', 'array'],
            'metadata' => ['nullable'],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
}
