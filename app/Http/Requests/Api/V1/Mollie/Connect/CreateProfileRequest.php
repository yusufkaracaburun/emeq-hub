<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie\Connect;

use Illuminate\Foundation\Http\FormRequest;

class CreateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ability-guard staat in AbstractMollieConnectPassThroughController::handle()
        // (D-14 — Phase-5a-pattern).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'website' => ['required', 'url', 'max:255'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:500'],
            'businessCategory' => ['nullable', 'string'],
            'mode' => ['nullable', 'string', 'in:live,test'],
        ];
    }
}
