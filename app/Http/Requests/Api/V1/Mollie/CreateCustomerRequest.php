<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'locale' => ['nullable', 'string'],
            'metadata' => ['nullable'],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
}
