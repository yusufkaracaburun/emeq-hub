<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDemoRequestRequest extends FormRequest
{
    /**
     * Voorkeursmomenten voor de demo — single source; de /demo-pagina krijgt
     * deze lijst als prop.
     *
     * @var list<string>
     */
    public const SLOTS = [
        'Deze week',
        'Volgende week',
        'Binnen twee weken',
        'In overleg',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'preferred_slot' => ['required', 'string', Rule::in(self::SLOTS)],
            'message' => ['nullable', 'string', 'max:2000'],
            'privacy_accepted' => ['accepted'],
            // 'website' is een honeypot — niet hier valideren (zou bots tippen),
            // de controller behandelt een gevuld honeypot-veld als stille no-op.
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company.required' => 'Vul je bedrijfsnaam in.',
            'contact_name.required' => 'Vul je naam in.',
            'email.required' => 'Vul je e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'preferred_slot.required' => 'Kies een voorkeursmoment.',
            'preferred_slot.in' => 'Kies een voorkeursmoment uit de lijst.',
            'privacy_accepted.accepted' => 'Ga akkoord met het privacybeleid om verder te gaan.',
        ];
    }
}
