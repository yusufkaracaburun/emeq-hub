<?php

declare(strict_types=1);

namespace Tests\Feature\Dev;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExactOAuthTracerTest extends TestCase
{
    public function test_info_page_responds(): void
    {
        $this->get('/dev/exact/info')
            ->assertOk()
            ->assertSee('Exact');
    }

    public function test_start_redirects_to_exact_authorize_with_correct_params(): void
    {
        config([
            'services.exact.client_id' => 'test-client-id',
            'services.exact.redirect_uri' => 'https://tunnel.example/dev/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        $response = $this->get('/dev/exact/start');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('https://start.exactonline.nl/api/oauth2/auth', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('response_type=code', $location);
        $this->assertStringContainsString('redirect_uri=', $location);
        $response->assertSessionHas('exact_tracer_state');
    }

    public function test_start_without_credentials_returns_500(): void
    {
        config([
            'services.exact.client_id' => '',
            'services.exact.redirect_uri' => '',
        ]);

        $this->get('/dev/exact/start')->assertStatus(500);
    }

    public function test_callback_with_error_param_returns_400(): void
    {
        $this->get('/dev/exact/callback?error=access_denied&error_description=nope')
            ->assertStatus(400)
            ->assertSee('access_denied');
    }

    public function test_callback_without_code_returns_400(): void
    {
        $this->get('/dev/exact/callback')->assertStatus(400);
    }

    public function test_callback_exchanges_code_for_tokens(): void
    {
        config([
            'services.exact.client_id' => 'test-client-id',
            'services.exact.client_secret' => 'test-secret',
            'services.exact.redirect_uri' => 'https://tunnel.example/dev/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        Http::fake([
            'start.exactonline.nl/api/oauth2/token' => Http::response(['access_token' => 'acc-1', 'token_type' => 'bearer', 'expires_in' => '600', 'refresh_token' => 'ref-1'], 200),
            'start.exactonline.nl/api/v1/current/Me' => Http::response(['d' => ['results' => [['CurrentDivision' => 123456]]]], 200),
        ]);

        $this->get('/dev/exact/callback?code=the-auth-code&state=abc')
            ->assertOk()
            ->assertSee('geslaagd')
            ->assertSee('division: 123456');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://start.exactonline.nl/api/oauth2/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'the-auth-code';
        });

        $this->assertNotNull(Cache::get('exact_tracer:last_token'));
    }

    public function test_refresh_confirms_rotation_when_token_returned_differs(): void
    {
        config([
            'services.exact.client_id' => 'test-client-id',
            'services.exact.client_secret' => 'test-secret',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        Cache::put('exact_tracer:last_token', [
            'access_token' => 'acc-old',
            'refresh_token' => 'ref-old',
            'expires_in' => 600,
            'issued_at' => now()->timestamp,
        ], now()->addHour());

        Http::fake([
            'start.exactonline.nl/api/oauth2/token' => Http::response([
                'access_token' => 'acc-new', 'token_type' => 'bearer', 'expires_in' => '600', 'refresh_token' => 'ref-new',
            ], 200),
        ]);

        $this->get('/dev/exact/refresh')
            ->assertOk()
            ->assertSee('NIEUW refresh_token');

        $this->assertSame('ref-new', Cache::get('exact_tracer:last_token')['refresh_token']);
    }

    public function test_refresh_without_stash_returns_400(): void
    {
        Cache::forget('exact_tracer:last_token');

        $this->get('/dev/exact/refresh')->assertStatus(400);
    }

    public function test_refresh_reports_not_expired_when_exact_refuses(): void
    {
        config([
            'services.exact.client_id' => 'test-client-id',
            'services.exact.client_secret' => 'test-secret',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
        ]);

        Cache::put('exact_tracer:last_token', [
            'access_token' => 'acc-old',
            'refresh_token' => 'ref-old',
            'expires_in' => 600,
            'issued_at' => now()->timestamp,
        ], now()->addHour());

        Http::fake([
            'start.exactonline.nl/api/oauth2/token' => Http::response([
                'error' => 'access_denied', 'error_description' => 'Rate limit exceeded: access_token not expired',
            ], 400),
        ]);

        $this->get('/dev/exact/refresh')
            ->assertOk()
            ->assertSee('nog niet verlopen');
    }
}
