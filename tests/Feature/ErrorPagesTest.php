<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Branded error-pages renderen (standalone Blade, géén Inertia — overleeft een
 * stukke JS-bundle bij een 500).
 */
class ErrorPagesTest extends TestCase
{
    /**
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function errorPages(): array
    {
        return [
            '404' => ['errors.404', '404', 'Pagina niet gevonden'],
            '500' => ['errors.500', '500', 'Er ging iets mis'],
            '503' => ['errors.503', '503', 'Even offline voor onderhoud'],
            '403' => ['errors.403', '403', 'Geen toegang'],
        ];
    }

    #[DataProvider('errorPages')]
    public function test_error_view_renders_branded(string $view, string $code, string $title): void
    {
        $html = view($view)->render();

        $this->assertStringContainsString($code, $html);
        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('Terug naar home', $html);
        $this->assertStringContainsString('emeq hub', $html);
    }

    public function test_unknown_route_returns_404(): void
    {
        $this->get('/deze-pagina-bestaat-niet-zzz')->assertNotFound();
    }
}
