<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Seo\Schema;
use App\Support\Seo\SeoMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;

class DocsController extends Controller
{
    private const GUIDE_PATH = 'docs/consumer-integration-guide.md';

    public function integrationGuide(): Response
    {
        $path = base_path(self::GUIDE_PATH);
        $title = 'Consumer-integratiehandleiding';
        $url = route('docs.integration-guide');

        $exists = is_file($path);
        $markdown = $exists ? (string) file_get_contents($path) : '';

        // De pagina rendert zelf al een <h1>; een leidende # in de bron zou
        // een tweede <h1> in de body opleveren.
        $markdown = (string) preg_replace('/^#[ \t]+.*\R+/', '', $markdown, 1);

        return Inertia::render('integration-guide', [
            'title' => $title,
            'html' => Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'heading_permalink' => ['insert' => 'none', 'apply_id_to_heading' => true, 'id_prefix' => ''],
            ], [
                new TableExtension,
                new HeadingPermalinkExtension,
            ]),
            'updatedAt' => Carbon::createFromTimestamp($exists ? filemtime($path) : time())->toDateString(),
            'apiReferenceUrl' => route('scramble.docs.ui'),
            'seo' => SeoMeta::make(
                $title,
                'Endpoints, payloads en agent-prompts voor consumer-apps die aan de emeq Hub koppelen.',
                $url,
            )->type('article')->schema(
                Schema::breadcrumbs([
                    ['name' => 'Home', 'url' => route('home')],
                    ['name' => $title, 'url' => $url],
                ]),
            ),
        ]);
    }
}
