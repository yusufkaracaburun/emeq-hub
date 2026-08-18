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
            'answer' => 'Vraag een koppeling aan. We beoordelen je use-case, richten je omgeving in '
                .'en sturen je token — meestal binnen één werkdag.',
        ],
        [
            'question' => 'Welke koppelingen zijn live?',
            'answer' => 'Exact Online is de eerste live koppeling. We breiden de Hub uit met nieuwe '
                .'integraties, terwijl jouw API-contract vertrouwd blijft.',
        ],
        [
            'question' => 'Wat kost het?',
            'answer' => 'Je betaalt passend bij je volume en use-case. Neem contact op voor een '
                .'voorstel dat met je meegroeit.',
        ],
        [
            'question' => 'Hoe zit het met beveiliging?',
            'answer' => 'Credentials zijn versleuteld, secrets zijn per koppeling gescheiden en elke '
                .'relevante call is te herleiden in de audit-log.',
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
