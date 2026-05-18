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
            // Vendor SDK Mollie\Api\Http\Requests\CreateProfileRequest::__construct
            // typeert $phone als non-nullable string — verplicht in Form Request
            // anders bubble't een PHP TypeError door als mollie_unknown (502).
            'phone' => ['required', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:500'],
            'businessCategory' => ['nullable', 'string'],
            'mode' => ['nullable', 'string', 'in:live,test'],
            'countriesOfActivity' => ['nullable', 'array'],
            'countriesOfActivity.*' => ['string', 'size:2'],
        ];
    }
}
