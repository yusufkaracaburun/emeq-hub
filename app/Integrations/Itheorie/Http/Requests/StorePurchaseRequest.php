<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mobile_phone' => ['nullable', 'string', 'max:32'],
            'permission_to_share_progress' => ['nullable', 'boolean'],
        ];
    }
}
