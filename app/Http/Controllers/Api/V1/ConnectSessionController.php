<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Controllers\Controller;
use App\Integrations\OAuth\ReturnUrlResolver;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use App\Support\Connect\ConnectLinkFactory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group(name: 'Integrations', description: 'Welke providers een Account kan koppelen, met live status.', weight: 25)]
class ConnectSessionController extends Controller
{
    use GuardsTokenAbility;

    /** @return array{url: string, expires_at: string} */
    public function __invoke(
        Request $request,
        ConnectLinkFactory $links,
        ReturnUrlResolver $returnUrls,
    ): array {
        $this->guardAbility($request, [
            TokenAbilities::INTEGRATIONS_MANAGE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::ADMIN,
        ]);

        $validated = $request->validate([
            'account_external_id' => ['required', 'string'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'return_url' => ['nullable', 'url'],
            'categories' => ['sometimes', 'array'],
            'categories.*.key' => ['required', 'string', 'max:255', 'distinct'],
            'categories.*.label' => ['required', 'string', 'max:255'],
            'categories.*.type' => ['required', Rule::in(['expense', 'income'])],
            'mode' => ['sometimes', Rule::in(['manage', ConnectLinkFactory::MODE_VIEW])],
            'actor' => ['sometimes', 'array'],
            'actor.name' => ['required_with:actor', 'string', 'max:255'],
            'actor.email' => ['required_with:actor', 'email', 'max:255'],
        ]);

        /** @var Consumer $consumer */
        $consumer = $request->user();

        $account = $consumer->accounts()->firstOrCreate(
            ['external_id' => $validated['account_external_id']],
            ['display_name' => $validated['display_name'] ?? null],
        );

        if (($validated['display_name'] ?? null) !== null && $account->display_name !== $validated['display_name']) {
            $account->update(['display_name' => $validated['display_name']]);
        }

        if (array_key_exists('categories', $validated)) {
            $account->update(['accounting_categories' => $validated['categories']]);
        }

        $link = $links->mint(
            $account,
            $returnUrls->resolveHandoff($consumer, $validated['return_url'] ?? null, $request->headers->get('Origin')),
            carried: $links->sessionParameters($validated['mode'] ?? 'manage', $validated['actor'] ?? null),
        );

        return [
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
        ];
    }
}
