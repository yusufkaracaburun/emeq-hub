<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\LegalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Publieke privacyverklaring op /privacy. Markdown uit LegalSettings, server-side
 * naar HTML gerenderd. Indexeerbaar (zie SetNoIndexHeaders).
 */
class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_renders_inertia_component_with_rendered_html(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('privacy')
                ->where('updatedAt', '2026-07-20')
                ->where('html', fn (string $html) => str_contains($html, '<h1') && str_contains($html, 'Privacyverklaring'))
            );
    }

    public function test_privacy_is_indexable(): void
    {
        $this->get('/privacy')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_privacy_html_strips_raw_html(): void
    {
        // Admin-content is vertrouwd, maar rauwe HTML mag niet doorlekken.
        app(LegalSettings::class)->fill(['privacy_statement' => "# Titel\n\n<script>alert(1)</script>", 'privacy_updated_at' => '2026-07-20'])->save();

        $this->get('/privacy')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('html', fn (string $html) => ! str_contains($html, '<script>'))
            );
    }
}
