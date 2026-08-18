<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
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
