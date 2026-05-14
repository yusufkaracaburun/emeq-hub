<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edge-validatie voor POST /v1/mollie/payments — vangt overduidelijke
 * payload-fouten af vóór Mollie-roundtrip (lager Mollie-quota-burn).
 * Mollie zelf doet de echte business-validatie.
 *
 * Regels uit .docs/partners/mollie/payments-api.md.
 */
class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth gebeurt op middleware-niveau (auth:sanctum + resolve.mollie.account
        // + ability-guard in AbstractMolliePassThroughController).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
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
