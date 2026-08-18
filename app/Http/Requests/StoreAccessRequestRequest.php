<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $providers = array_keys(config('hub-providers', []));

        return [
            'company' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'app_url' => ['nullable', 'url', 'max:255'],
            'providers' => ['required', 'array', 'min:1'],
            'providers.*' => ['string', Rule::in($providers)],
            'message' => ['nullable', 'string', 'max:2000'],
            'privacy_accepted' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'company.required' => 'Vul je bedrijfsnaam in.',
            'contact_name.required' => 'Vul je naam in.',
            'email.required' => 'Vul je e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'app_url.url' => 'Vul een geldige URL in (incl. https://).',
            'providers.required' => 'Kies minstens één integratie.',
            'providers.min' => 'Kies minstens één integratie.',
            'privacy_accepted.accepted' => 'Ga akkoord met het privacybeleid om verder te gaan.',
        ];
    }
}
