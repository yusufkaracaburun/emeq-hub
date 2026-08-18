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

        $link = $links->mint(
            $account,
            $returnUrls->resolveHandoff($consumer, $validated['return_url'] ?? null, $request->headers->get('Origin')),
        );

        return [
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
        ];
    }
}
