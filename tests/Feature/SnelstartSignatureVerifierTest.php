<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Webhooks\SnelstartSignatureVerifier;
use Tests\TestCase;

/**
 * Plan 05c-02 Task 2 — verifier-class behavior.
 *
 * CONTEXT 🔒 #1: HMAC-SHA256 over raw body, hex-encoded. Defensief
 * via algo-parameter zodat env-override partner-respons-resistent is.
 * Rotation-window per CONTEXT 🔒 #2: array van secrets is voldoende.
 */
final class SnelstartSignatureVerifierTest extends TestCase
{
    public function test_valid_signature_passes(): void
    {
        $body = '{"event":"Relatie.Created","administratieId":"00000000-0000-0000-0000-000000000001"}';
        $secret = 'shared-secret-from-snelstart-portal';

        $signature = SnelstartSignatureVerifier::sign($body, $secret);

        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $signature, $secret));
    }

    public function test_invalid_signature_fails(): void
    {
        $body = '{"event":"Relatie.Created"}';
        $secret = 'correct-secret';
        $wrongSignature = SnelstartSignatureVerifier::sign($body, 'attacker-guess');

        $this->assertFalse(SnelstartSignatureVerifier::verify($body, $wrongSignature, $secret));
    }

    public function test_null_or_empty_header_fails(): void
    {
        $body = 'doesnt-matter';
        $secret = 'any-secret';

        $this->assertFalse(SnelstartSignatureVerifier::verify($body, null, $secret));
        $this->assertFalse(SnelstartSignatureVerifier::verify($body, '', $secret));
    }

    public function test_rotation_window_accepts_either_secret(): void
    {
        $body = '{"event":"Verkoopfactuur.Created"}';
        $secretA = 'primary-secret-active';
        $secretB = 'rotating-in-secret-next';

        $signedWithA = SnelstartSignatureVerifier::sign($body, $secretA);
        $signedWithB = SnelstartSignatureVerifier::sign($body, $secretB);

        // Beide volgordes moeten matchen — verifier itereert door alle secrets.
        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $signedWithA, [$secretA, $secretB]));
        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $signedWithA, [$secretB, $secretA]));
        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $signedWithB, [$secretA, $secretB]));
        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $signedWithB, [$secretB, $secretA]));
    }

    public function test_empty_secret_array_fails(): void
    {
        $body = 'any-body';
        // Sign met een willekeurig secret zodat de header een geldige hex-string is — de verifier
        // moet alsnog false retourneren omdat er geen candidates zijn om tegen te valideren.
        $validHeader = SnelstartSignatureVerifier::sign($body, 'unrelated');

        $this->assertFalse(SnelstartSignatureVerifier::verify($body, $validHeader, []));
    }

    public function test_different_algo_works(): void
    {
        $body = '{"event":"Relatie.Updated"}';
        $secret = 'shared-secret';
        $sha512 = SnelstartSignatureVerifier::sign($body, $secret, 'sha512');

        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $sha512, $secret, 'sha512'));
        // Default-algo (sha256) tegen een sha512-signature moet falen.
        $this->assertFalse(SnelstartSignatureVerifier::verify($body, $sha512, $secret));
    }

    public function test_secrets_array_with_null_and_empty_entries_is_sanitized(): void
    {
        $body = '{"event":"Relatie.Created"}';
        $secret = 'real-secret';
        $signature = SnelstartSignatureVerifier::sign($body, $secret);

        $this->assertTrue(SnelstartSignatureVerifier::verify($body, $signature, [null, '', $secret]));
    }
}
