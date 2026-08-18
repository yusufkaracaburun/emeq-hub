<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ProviderShowcase;
use Illuminate\Http\Response;

class LlmsController extends Controller
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    public function __invoke(): Response
    {
        $providers = $this->showcase->summaries();

        $integrations = array_map(fn (array $provider): string => sprintf(
            '- [%s](%s): %s — %s. Status: %s.',
            $provider['label'],
            route('partners.show', $provider['key']),
            $provider['category'],
            rtrim($provider['summary'], '.'),
            $provider['live'] ? 'live' : 'in voorbereiding',
        ), $providers);

        $lines = [
            '# emeq Hub',
            '',
            '> Integratieplatform voor Nederlandse boekhoud- en betaalsoftware. Eén REST-API '
                .'waarachter de Hub OAuth-koppelingen, tokenbeheer, webhook-routing en '
                .'audit-logging per eindklant afhandelt, zodat een SaaS-app niet per partner '
                .'een eigen integratie hoeft te bouwen.',
            '',
            'Taal: Nederlands. Doelgroep: software-teams die een boekhoud- of betaalkoppeling '
                .'in hun eigen product willen aanbieden.',
            '',
            '## Koppelingen',
            '',
            ...$integrations,
            '',
            '## Pagina\'s',
            '',
            '- ['.config('app.name').']('.route('home').'): wat de Hub doet en voor wie.',
            '- [Partners]('.route('partners.index').'): overzicht van alle koppelingen.',
            '- [Support]('.route('support').'): contact en veelgestelde vragen.',
            '',
            '## Juridisch',
            '',
            '- [Privacyverklaring]('.route('privacy').')',
            '- [Algemene voorwaarden]('.route('terms').')',
            '- [Verwerkersovereenkomst]('.route('processor-agreement').'): de Hub treedt op als verwerker.',
            '',
            '## Contact',
            '',
            '- Algemeen: info@emeq.nl',
            '- Support: support@emeq.nl',
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
