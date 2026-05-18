<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mollie\Connect;

use Illuminate\Foundation\Http\FormRequest;

class CreateClientLinkRequest extends FormRequest
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
            'owner' => ['required', 'array'],
            'owner.email' => ['required', 'email'],
            'owner.givenName' => ['required', 'string', 'max:255'],
            'owner.familyName' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'array'],
            'address.streetAndNumber' => ['required', 'string', 'max:255'],
            'address.postalCode' => ['required', 'string', 'max:32'],
            'address.city' => ['required', 'string', 'max:255'],
            'address.country' => ['required', 'string', 'size:2'],
            'registrationNumber' => ['nullable', 'string', 'max:64'],
            'vatNumber' => ['nullable', 'string', 'max:64'],
        ];
    }
}
