<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $consumerId = (int) $this->user()?->getKey();

        return [
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('consumer_id', $consumerId),
            ],
            'provider' => ['required', 'string', Rule::in(['snelstart'])],
            'credentials' => ['required', 'array'],
            'credentials.client_key' => ['required_if:provider,snelstart', 'string', 'min:10'],
            'credentials.subscription_key' => ['required_if:provider,snelstart', 'string', 'min:10'],
            'credentials.subscription_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
