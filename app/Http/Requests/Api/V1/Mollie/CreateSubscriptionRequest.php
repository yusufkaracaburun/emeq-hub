<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edge-validatie voor POST /v1/mollie/customers/{id}/subscriptions —
 * vangt grove payload-fouten af vóór Mollie-roundtrip (lager
 * Mollie-quota-burn).
 *
 * Required-fields per Mollie's Create Subscription API:
 *   amount.currency  (ISO 4217 3-letter)
 *   amount.value     (decimal-string met >=2 cijfers achter de komma)
 *   interval         ("N day(s)" | "N week(s)" | "N month(s)")
 *   description      (max 255 chars)
 *
 * Optionele velden: startDate, method, metadata, mandateId, webhookUrl,
 * times. Niet-genoemde Mollie-velden worden niet gevalideerd; Mollie
 * zelf valideert ze als ze in de payload zitten.
 */
class CreateSubscriptionRequest extends FormRequest
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
