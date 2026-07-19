<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Settings\LegalSettings;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Publieke privacyverklaring. De tekst wordt als markdown beheerd in de admin
 * (ManageLegalPages) en hier server-side naar HTML gerenderd. `html_input` op
 * strip + geen unsafe links: admin-content is vertrouwd, maar we laten geen
 * rauwe HTML door.
 */
class PrivacyController extends Controller
{
    public function show(LegalSettings $legal): Response
    {
        return Inertia::render('privacy', [
            'html' => Str::markdown($legal->privacy_statement, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ], [
                new TableExtension,
            ]),
            'updatedAt' => $legal->privacy_updated_at,
        ]);
    }
}
