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

/**
 * Mint een kortlevende, getekende handoff-URL waar de consumer-app zijn eigen
 * eindgebruiker naartoe stuurt om zelf een koppeling te starten.
 *
 * De consumer-app roept dit server-side aan op het moment dat hij de
 * koppel-banner rendert voor een ingelogde eindgebruiker; de knop krijgt de
 * teruggegeven URL als href. Zo blijft de keten
 * `PAT → Consumer → Account → Connection` intact zonder dat de Hub de
 * eindgebruiker zelf hoeft te authenticeren.
 */
#[Group(name: 'Integrations', description: 'Welke providers een Account kan koppelen, met live status.', weight: 25)]
class ConnectSessionController extends Controller
{
    use GuardsTokenAbility;

    /**
     * @return array{url: string, expires_at: string}
     */
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

        // Zelfde één-knop-onboarding als de OAuth-init: het Account hoeft niet
        // vooraf te bestaan. Scoped op $consumer->accounts(), dus external_id is
        // per-Consumer genamespaced en kan niet naar een ander tenant wijzen.
        $account = $consumer->accounts()->firstOrCreate(
            ['external_id' => $validated['account_external_id']],
            ['display_name' => $validated['display_name'] ?? null],
        );

        // display_name kan bij een later request alsnog meekomen; de eindgebruiker
        // moet op de handoff-pagina zien namens welke administratie hij koppelt.
        if (($validated['display_name'] ?? null) !== null && $account->display_name !== $validated['display_name']) {
            $account->update(['display_name' => $validated['display_name']]);
        }

        $link = $links->mint(
            $account,
            $returnUrls->resolve($consumer, $validated['return_url'] ?? null, $request->headers->get('Origin')),
        );

        return [
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
        ];
    }
}
