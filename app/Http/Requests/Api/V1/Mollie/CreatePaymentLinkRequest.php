<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edge-validatie voor POST /v1/mollie/payment-links — vangt grove
 * payload-fouten af vóór Mollie-roundtrip.
 *
 * Required-fields per Mollie's Create Payment Link API:
 *   description (max 255 chars)
 *
 * Mollie zelf handhaaft mutual-exclusion tussen `amount` en
 * `minimumAmount` — Hub valideert alleen dat per-shape correct is
 * wanneer aanwezig.
 */
class CreatePaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
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
