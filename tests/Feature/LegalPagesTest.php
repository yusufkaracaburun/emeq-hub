<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\LegalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke juridische pagina's op /privacy en /voorwaarden. Markdown uit
 * LegalSettings, server-side naar HTML gerenderd. Indexeerbaar (SetNoIndexHeaders).
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_renders_with_company_details(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('legal')
                ->where('title', 'Privacyverklaring')
                ->where('updatedAt', '2026-07-20')
                ->where('html', fn (string $html) => str_contains($html, 'Privacyverklaring') && str_contains($html, 'KvK 84148691'))
            );
    }

    public function test_terms_renders_with_dutch_law_clause(): void
    {
        $this->get('/voorwaarden')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('legal')
                ->where('title', 'Algemene voorwaarden')
                ->where('html', fn (string $html) => str_contains($html, 'Algemene voorwaarden') && str_contains($html, 'Nederlands recht'))
            );
    }

    public function test_processor_agreement_renders_as_processor(): void
    {
        $this->get('/verwerkersovereenkomst')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('legal')
                ->where('title', 'Verwerkersovereenkomst')
                ->where('html', fn (string $html) => str_contains($html, 'Verwerkersovereenkomst') && str_contains($html, 'verwerker') && str_contains($html, 'KvK 84148691'))
            );
    }

    public function test_all_legal_pages_are_indexable(): void
    {
        $this->get('/privacy')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/voorwaarden')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/verwerkersovereenkomst')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_markdown_strips_raw_html(): void
    {
        app(LegalSettings::class)->fill([
            'privacy_statement' => "# Titel\n\n<script>alert(1)</script>",
            'privacy_updated_at' => '2026-07-20',
        ])->save();

        $this->get('/privacy')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('html', fn (string $html) => ! str_contains($html, '<script>'))
            );
    }
}
