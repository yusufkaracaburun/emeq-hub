<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ProviderShowcase;
use App\Support\Seo\SeoMeta;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    public function index(): Response
    {
        return Inertia::render('home', [
            'providers' => $this->showcase->summaries(),
            'seo' => SeoMeta::make(
                'Eén API voor al je integraties',
                'Emeq Hub is het integratieplatform voor Nederlandse software: één unified API '
                    .'voor elke externe koppeling. OAuth, tokenbeheer en webhooks zijn geregeld.',
            ),
        ]);
    }
}
