<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\PassThroughCall;
use App\Webhooks\SnelstartSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 05c-02 Task 3 — middleware-behavior.
 *
 * CONTEXT-locked decisions:
 *  - Hardfail (geen secret) → 500 + audit-rij `webhook_secret_not_configured`
 *  - Invalid HMAC → 401 + lege body + GEEN audit (anti-amplification)
 *  - Valid HMAC → next handler
 *  - Config-driven header-naam + algo (partner-respons mag env-vars wijzigen)
 *  - Rotation-window via webhook_secret + webhook_secret_next
 */
final class VerifySnelstartSignatureMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ROUTE = '/__test/snelstart-webhook';

    private const PRIMARY_SECRET = 'primary-shared-secret-from-snelstart';

    private const SECONDARY_SECRET = 'rotating-in-next-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.snelstart.webhook_secret' => self::PRIMARY_SECRET,
            'services.snelstart.webhook_secret_next' => null,
            'services.snelstart.webhook_signature_header' => 'X-SnelStart-Signature',
            'services.snelstart.webhook_signature_algo' => 'sha256',
        ]);

        Route::post(self::TEST_ROUTE, fn () => response('ok', 200))
            ->middleware('verify.snelstart.signature');
    }

    public function test_valid_signature_passes_through(): void
    {
        $body = '{"event":"Relatie.Created","administratieId":"00000000-0000-0000-0000-000000000001"}';
        $signature = SnelstartSignatureVerifier::sign($body, self::PRIMARY_SECRET);

        $response = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_SNELSTART_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(200);
        $this->assertSame('ok', $response->getContent());
        $this->assertSame(0, PassThroughCall::query()->count(), 'middleware schrijft géén audit op happy-pad');
    }

    public function test_invalid_signature_returns_401_empty_body(): void
    {
        $body = '{"event":"Relatie.Created"}';
        $wrongSignature = SnelstartSignatureVerifier::sign($body, 'wrong-secret');

        $response = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_SNELSTART_SIGNATURE' => $wrongSignature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(401);
        $this->assertSame('', $response->getContent(), 'invalid-pad mag GEEN body lekken');
        $this->assertSame(0, PassThroughCall::query()->count(), 'invalid-pad mag GEEN audit-rij schrijven (anti-amplification)');
    }

    public function test_missing_header_returns_401(): void
    {
        $body = '{"event":"Relatie.Created"}';

        $response = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(401);
        $this->assertSame('', $response->getContent());
        $this->assertSame(0, PassThroughCall::query()->count());
    }

    public function test_missing_secret_returns_500_with_audit_row(): void
    {
        config([
            'services.snelstart.webhook_secret' => null,
            'services.snelstart.webhook_secret_next' => null,
        ]);

        $body = '{"event":"Relatie.Created"}';

        $response = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_SNELSTART_SIGNATURE' => 'anything', 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(500);

        $row = PassThroughCall::query()->inbound()
            ->where('upstream_error', 'webhook_secret_not_configured')
            ->first();

        $this->assertNotNull($row, 'hardfail moet een audit-rij schrijven');
        $this->assertSame('snelstart', $row->provider);
        $this->assertSame('POST', $row->method);
        $this->assertSame(500, $row->status);
        $this->assertNull($row->consumer_id);
        $this->assertNull($row->account_id);
        $this->assertNull($row->connection_id);
    }

    public function test_rotation_window_accepts_secondary_secret(): void
    {
        config([
            'services.snelstart.webhook_secret' => self::PRIMARY_SECRET,
            'services.snelstart.webhook_secret_next' => self::SECONDARY_SECRET,
        ]);

        $body = '{"event":"Verkoopfactuur.Created"}';
        // Signeer met de SECONDARY-secret — die zou Snelstart sturen tijdens de rotation-window.
        $signature = SnelstartSignatureVerifier::sign($body, self::SECONDARY_SECRET);

        $response = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_SNELSTART_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(200);
    }

    public function test_custom_header_name_works(): void
    {
        config(['services.snelstart.webhook_signature_header' => 'X-Custom-Sig']);

        $body = '{"event":"Relatie.Created"}';
        $signature = SnelstartSignatureVerifier::sign($body, self::PRIMARY_SECRET);

        // Default-header (X-SnelStart-Signature) MOET genegeerd worden — alleen X-Custom-Sig telt.
        $blockedResponse = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_SNELSTART_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $blockedResponse->assertStatus(401);

        // Op de custom-header MOET de signature wel werken.
        $allowedResponse = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_CUSTOM_SIG' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $allowedResponse->assertStatus(200);
    }

    public function test_custom_algo_via_config_works(): void
    {
        config(['services.snelstart.webhook_signature_algo' => 'sha512']);

        $body = '{"event":"Relatie.Created"}';
        $signature = SnelstartSignatureVerifier::sign($body, self::PRIMARY_SECRET, 'sha512');

        $response = $this->call(
            method: 'POST',
            uri: self::TEST_ROUTE,
            server: ['HTTP_X_SNELSTART_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(200);
    }
}
