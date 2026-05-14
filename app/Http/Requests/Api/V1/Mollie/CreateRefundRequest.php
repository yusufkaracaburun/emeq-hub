<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edge-validatie voor POST /v1/mollie/payments/{id}/refunds. Vangt
 * grove payload-fouten af vóór Mollie-roundtrip.
 *
 * Regels uit .docs/partners/mollie/refunds-api.md.
 */
class CreateRefundRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:255'],
            'externalReference' => ['nullable', 'array'],
            'metadata' => ['nullable'],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
}
