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
                'Eén API voor boekhoud- en betaalkoppelingen',
                'Emeq Hub is de unified API voor Nederlandse boekhoud- en betaalkoppelingen. '
                    .'OAuth, tokenbeheer en webhooks zijn geregeld; jij bouwt verder aan je product.',
            ),
        ]);
    }
}
