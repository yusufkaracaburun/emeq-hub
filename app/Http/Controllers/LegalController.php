<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Settings\LegalSettings;
use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\Table\TableExtension;

class LegalController extends Controller
{
    public function privacy(LegalSettings $legal): Response
    {
        return $this->render(
            'Privacyverklaring',
            'privacy',
            $legal->privacy_statement,
            $legal->privacy_updated_at,
            'Hoe emeq persoonsgegevens verwerkt bij het gebruik van de Hub: welke gegevens, '
                .'op welke grondslag, hoe lang en met welke sub-verwerkers.',
        );
    }

    public function terms(LegalSettings $legal): Response
    {
        return $this->render(
            'Algemene voorwaarden',
            'terms',
            $legal->terms_statement,
            $legal->terms_updated_at,
            'De voorwaarden waaronder emeq de Hub levert: gebruik, beschikbaarheid, '
                .'aansprakelijkheid en opzegging.',
        );
    }

    public function processorAgreement(LegalSettings $legal): Response
    {
        return $this->render(
            'Verwerkersovereenkomst',
            'processor-agreement',
            $legal->dpa_statement,
            $legal->dpa_updated_at,
            'De verwerkersovereenkomst tussen jou als verwerkingsverantwoordelijke en emeq als '
                .'verwerker, inclusief sub-verwerkers en beveiligingsmaatregelen.',
        );
    }

    private function render(
        string $title,
        string $routeName,
        string $markdown,
        string $updatedAt,
        string $description,
    ): Response {
        $url = route($routeName);

        $markdown = (string) preg_replace('/^##\s+\d+[.)]\s*/m', '## ', $markdown);

        return Inertia::render('legal', [
            'title' => $title,
            'html' => Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ], [
                new TableExtension,
            ]),
            'updatedAt' => $updatedAt,
            'seo' => SeoMeta::make($title, $description, $url)
                ->type('article')
                ->schema(
                    Schema::breadcrumbs([
                        ['name' => 'Home', 'url' => route('home')],
                        ['name' => $title, 'url' => $url],
                    ]),
                    Schema::legalPage($title, $url, $this->isoDate($updatedAt)),
                ),
        ]);
    }

    private function isoDate(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
