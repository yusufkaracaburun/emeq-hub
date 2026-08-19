<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    /** @var list<array{question:string,answer:string}> */
    private const FAQ = [
        [
            'question' => 'Hoe krijg ik een API-token?',
            'answer' => 'Vraag een koppeling aan. We kijken naar wat je wilt bouwen, richten je omgeving in '
                .'en sturen je token, meestal binnen één werkdag.',
        ],
        [
            'question' => 'Welke koppelingen zijn live?',
            'answer' => 'Exact Online is nu live. Komt er een partner bij, dan verandert het API-contract '
                .'niet.',
        ],
        [
            'question' => 'Wat kost het?',
            'answer' => 'De prijs hangt af van je volume. Neem contact op voor een voorstel.',
        ],
        [
            'question' => 'Hoe zit het met beveiliging?',
            'answer' => 'Credentials zijn versleuteld, elke koppeling heeft een eigen secret en elke call '
                .'is terug te vinden in de audit-log.',
        ],
    ];

    public function __invoke(): Response
    {
        return Inertia::render('support', [
            'faq' => self::FAQ,
            'seo' => SeoMeta::make(
                'Support',
                'Hulp bij een koppeling, API-token of technische keuze. Ons team reageert binnen '
                    .'één werkdag via support@emeq.nl.',
            )->schema(
                Schema::breadcrumbs([
                    ['name' => 'Home', 'url' => route('home')],
                    ['name' => 'Support', 'url' => route('support')],
                ]),
                Schema::faq(self::FAQ),
            ),
        ]);
    }
}
