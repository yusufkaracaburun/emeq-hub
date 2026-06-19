<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ProviderShowcase;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publieke marketing-homepage. Toont de waardepropositie van de Hub en de
 * beschikbare integraties — géén tenant-data. Indexeerbaar (zie SetNoIndexHeaders).
 */
class HomeController extends Controller
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    public function index(): Response
    {
        return Inertia::render('home', [
            'providers' => $this->showcase->summaries(),
        ]);
    }
}
