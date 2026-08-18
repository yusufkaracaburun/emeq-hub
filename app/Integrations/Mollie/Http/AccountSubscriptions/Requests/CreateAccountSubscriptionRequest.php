<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\AccountSubscriptions\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $consumerId = (int) $this->user()?->getKey();

        return [
            'account_external_id' => [
                'required',
                'string',
                Rule::exists('accounts', 'external_id')->where('consumer_id', $consumerId),
            ],
            'mollie_customer_id' => ['required', 'string', 'starts_with:cst_'],
            'mollie_mandate_id' => ['nullable', 'string', 'starts_with:mdt_'],
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'in:EUR'],
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2}$/'],
            'interval' => ['required', 'string', 'regex:/^\d+\s+(day|days|week|weeks|month|months)$/'],
            'description' => ['required', 'string', 'max:255'],
            'times' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_external_id.exists' => 'Account niet gevonden voor deze Consumer.',
            'mollie_customer_id.starts_with' => 'Mollie customer-id moet beginnen met "cst_".',
            'mollie_mandate_id.starts_with' => 'Mollie mandate-id moet beginnen met "mdt_".',
            'amount.value.regex' => 'Bedrag moet een decimale string zijn met exact 2 decimalen (bv. "10.00").',
            'amount.currency.in' => 'Alleen EUR wordt ondersteund in v0.2.',
            'interval.regex' => 'Interval moet voldoen aan Mollie-format: "1 month" / "2 weeks" / "5 days".',
        ];
    }
}
