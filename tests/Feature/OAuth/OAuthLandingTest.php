<?php

namespace Tests\Feature\OAuth;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OAuthLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_page_renders_for_signed_url(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'status' => 'active',
            'administratie_id' => '4471372',
        ]);

        $url = URL::temporarySignedRoute('oauth.connected', now()->addMinutes(10), [
            'connection' => $connection->id,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Exact Online')
            ->assertSee('4471372');
    }

    public function test_connected_page_rejects_unsigned_url(): void
    {
        $connection = Connection::factory()->forExact()->create();

        $this->get("/oauth/connected/{$connection->id}")
            ->assertForbidden();
    }

    public function test_failed_page_renders_for_signed_url(): void
    {
        $url = URL::temporarySignedRoute('oauth.failed', now()->addMinutes(10), [
            'provider' => 'exact',
            'reason' => 'invalid_or_expired_state',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Exact Online')
            ->assertSee('niet voltooid');
    }

    public function test_failed_page_rejects_unsigned_url(): void
    {
        $this->get('/oauth/failed?provider=exact&reason=x')
            ->assertForbidden();
    }
}
