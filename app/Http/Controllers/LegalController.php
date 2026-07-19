<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Settings\LegalSettings;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Publieke juridische pagina's (privacyverklaring + algemene voorwaarden). De
 * teksten zijn markdown, beheerd in de admin (ManageLegalPages), en worden hier
 * server-side naar HTML gerenderd. `html_input` op strip + geen unsafe links:
 * admin-content is vertrouwd, maar we laten geen rauwe HTML door.
 */
class LegalController extends Controller
{
    public function privacy(LegalSettings $legal): Response
    {
        return $this->render('Privacyverklaring', $legal->privacy_statement, $legal->privacy_updated_at);
    }

    public function terms(LegalSettings $legal): Response
    {
        return $this->render('Algemene voorwaarden', $legal->terms_statement, $legal->terms_updated_at);
    }

    private function render(string $title, string $markdown, string $updatedAt): Response
    {
        return Inertia::render('legal', [
            'title' => $title,
            'html' => Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ], [
                new TableExtension,
            ]),
            'updatedAt' => $updatedAt,
        ]);
    }
}
