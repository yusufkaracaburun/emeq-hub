<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edge-validatie voor PATCH /v1/mollie/payments/{id} — Mollie staat
 * alleen description / metadata / redirectUrl / webhookUrl-mutaties toe
 * (zie .docs/partners/mollie/payments-api.md). Geen veld is required;
 * elk veld is independently nullable. PATCH-route zelf wordt in 05a-04+
 * geactiveerd indien nodig — request-class staat klaar.
 */
class UpdatePaymentRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable'],
            'redirectUrl' => ['nullable', 'url'],
            'cancelUrl' => ['nullable', 'url'],
            'webhookUrl' => ['nullable', 'url'],
        ];
    }
}
