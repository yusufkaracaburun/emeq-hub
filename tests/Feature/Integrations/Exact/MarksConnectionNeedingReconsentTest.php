<?php

namespace Tests\Feature\Integrations\Exact;

use App\Mail\ConnectionNeedsConsent;
use App\Models\Connection;
use Emeq\ExactApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarksConnectionNeedingReconsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_refresh_token_marks_the_connection_and_notifies_ops(): void
    {
        Mail::fake();

        $connection = Connection::factory()->forExact()->active()->create();

        report(AuthenticationException::refreshFailed(
            400,
            '{"error":"invalid_grant","error_description":"Token is not allowed, because of invalid or empty chainId"}',
            'fp',
            connectionRef: (string) $connection->id,
        ));

        $this->assertSame('needs_consent', $connection->fresh()->status);

        Mail::assertQueued(
            ConnectionNeedsConsent::class,
            fn (ConnectionNeedsConsent $mail): bool => $mail->needsConsentConnection->is($connection)
                && $mail->hasTo(config('mail.notify_address')),
        );
    }

    public function test_a_transient_refresh_failure_leaves_the_connection_active(): void
    {
        Mail::fake();

        $connection = Connection::factory()->forExact()->active()->create();

        report(AuthenticationException::refreshFailed(500, 'gateway down', 'fp', connectionRef: (string) $connection->id));

        $this->assertSame('active', $connection->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_it_only_marks_exact_connections(): void
    {
        Mail::fake();

        $exact = Connection::factory()->forExact()->active()->create();
        $snelstart = Connection::factory()->forSnelstart()->active()->create();

        report(AuthenticationException::refreshFailed(
            400,
            '{"error":"invalid_grant"}',
            'fp',
            connectionRef: (string) $snelstart->id,
        ));

        $this->assertSame('active', $snelstart->fresh()->status);
        $this->assertSame('active', $exact->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_a_repeated_failure_on_an_already_needs_consent_connection_does_not_resend_mail(): void
    {
        Mail::fake();

        $connection = Connection::factory()->forExact()->create(['status' => 'needs_consent']);

        report(AuthenticationException::refreshFailed(
            400,
            '{"error":"invalid_grant"}',
            'fp',
            connectionRef: (string) $connection->id,
        ));

        Mail::assertNothingQueued();
    }
}
