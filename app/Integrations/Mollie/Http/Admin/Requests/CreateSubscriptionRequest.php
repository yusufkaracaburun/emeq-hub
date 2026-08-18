<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Admin\Requests;

use App\Integrations\Mollie\Billing\PlanResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'consumer_id' => ['required', 'integer', 'exists:consumers,id'],
            'plan_slug' => ['required', 'string', Rule::in(array_keys($this->plansAllowed()))],
            'subscription_name' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'plan_slug.in' => 'Onbekende plan-slug. Definieer in config/billing-plans.php.',
            'consumer_id.exists' => 'Consumer bestaat niet.',
        ];
    }

    /** @return array<string, mixed> */
    private function plansAllowed(): array
    {
        return app(PlanResolver::class)->all();
    }
}
