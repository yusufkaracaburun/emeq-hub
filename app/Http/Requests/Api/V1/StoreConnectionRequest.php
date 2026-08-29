<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $consumerId = (int) $this->user()?->getKey();

        return [
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('consumer_id', $consumerId),
            ],
            'provider' => ['required', 'string', Rule::in([Provider::Snelstart->value, Provider::DataForSeo->value])],
            'credentials' => ['required', 'array'],
            'credentials.client_key' => ['required_if:provider,'.Provider::Snelstart->value, 'string', 'min:10'],
            'credentials.subscription_key' => ['required_if:provider,'.Provider::Snelstart->value, 'string', 'min:10'],
            'credentials.subscription_id' => ['nullable', 'string', 'max:255'],
            'credentials.access_token' => [
                'required_if:provider,'.Provider::DataForSeo->value,
                'string',
                'min:5',
                'regex:/^[^:]+:.+$/',
            ],
            'administratie_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
