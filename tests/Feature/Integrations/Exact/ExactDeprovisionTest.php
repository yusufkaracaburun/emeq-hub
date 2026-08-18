<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Exact;

use App\Integrations\Exact\Jobs\DeleteExactWebhookSubscriptionsJob;
use App\Integrations\Webhooks\InboundWebhookRecorder;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Mail\ConnectionDeprovisioned;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExactDeprovisionTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = 'd3b3f9a1-9c2e-4b7a-8f7e-2f4a1b6c9d0e';

    private const USER_ID_UPPER = 'D3B3F9A1-9C2E-4B7A-8F7E-2F4A1B6C9D0E';

    private function activeExactConnection(array $overrides = []): Connection
    {
        return Connection::factory()->forExact()->create(array_merge([
            'status' => 'active',
            'revoked_at' => null,
            'metadata' => ['exact_user_id' => self::USER_ID],
        ], $overrides));
    }

    public function test_stop_with_matching_user_id_shows_confirm_and_stores_connection_in_session(): void
    {
        $connection = $this->activeExactConnection();

        $this->get('/exact/stop?Country=NL&Language=nl-NL&UserId='.self::USER_ID)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('exact/stop')
                ->where('state', 'confirm'))
            ->assertSessionHas('exact_stop.connection_id', $connection->id);
    }

    public function test_stop_with_unknown_user_id_shows_soft_state(): void
    {
        $this->activeExactConnection();

        $this->get('/exact/stop?UserId=00000000-0000-0000-0000-000000000000')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('exact/stop')
                ->where('state', 'soft'))
            ->assertSessionMissing('exact_stop.connection_id');
    }

    /** @return array<string, array{0: string}> */
    public static function guidSpellings(): array
    {
        return [
            'hoofdletters' => [self::USER_ID_UPPER],
            'accolades' => ['{'.self::USER_ID.'}'],
            'accolades + hoofdletters' => ['{'.self::USER_ID_UPPER.'}'],
            'spaties eromheen' => [' '.self::USER_ID.' '],
        ];
    }

    #[DataProvider('guidSpellings')]
    public function test_stop_matches_regardless_of_guid_spelling(string $incoming): void
    {
        $connection = $this->activeExactConnection();

        $this->get('/exact/stop?UserId='.urlencode($incoming))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm'))
            ->assertSessionHas('exact_stop.connection_id', $connection->id);
    }

    /** @return array<string, array{0: string}> */
    public static function storedSpellings(): array
    {
        return [
            'opgeslagen in hoofdletters' => [self::USER_ID_UPPER],
            'opgeslagen met accolades' => ['{'.self::USER_ID.'}'],
            'opgeslagen met accolades + hoofdletters' => ['{'.self::USER_ID_UPPER.'}'],
        ];
    }

    #[DataProvider('storedSpellings')]
    public function test_stop_matches_legacy_stored_spellings(string $stored): void
    {
        $connection = $this->activeExactConnection([
            'metadata' => ['exact_user_id' => $stored],
        ]);

        $this->get('/exact/stop?UserId='.self::USER_ID)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm'))
            ->assertSessionHas('exact_stop.connection_id', $connection->id);
    }

    public function test_stop_with_blank_user_id_shows_soft_state(): void
    {
        $this->activeExactConnection();

        $this->get('/exact/stop?UserId='.urlencode('   '))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'soft'))
            ->assertSessionMissing('exact_stop.connection_id');
    }

    public function test_stop_without_user_id_shows_soft_state(): void
    {
        $this->get('/exact/stop')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('exact/stop')
                ->where('state', 'soft'));
    }

    public function test_stop_ignores_already_revoked_connections(): void
    {
        $this->activeExactConnection([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->get('/exact/stop?UserId='.self::USER_ID)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'soft'));
    }

    public function test_post_revokes_connection_and_redirects_to_done(): void
    {
        Bus::fake([DeleteExactWebhookSubscriptionsJob::class, ForwardConnectionRevokedToConsumerJob::class]);
        Mail::fake();
        $connection = $this->activeExactConnection();

        $this->withSession(['exact_stop.connection_id' => $connection->id])
            ->post('/exact/stop')
            ->assertRedirect(route('exact.stop.done'))
            ->assertSessionMissing('exact_stop.connection_id');

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNotNull($connection->revoked_at);

        Bus::assertDispatched(
            DeleteExactWebhookSubscriptionsJob::class,
            fn (DeleteExactWebhookSubscriptionsJob $job): bool => $job->exactConnection->is($connection),
        );
    }

    public function test_post_notifies_consumer_ops_and_audit(): void
    {
        Bus::fake([DeleteExactWebhookSubscriptionsJob::class, ForwardConnectionRevokedToConsumerJob::class]);
        Mail::fake();

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create([
            'status' => 'active',
            'revoked_at' => null,
            'metadata' => ['exact_user_id' => self::USER_ID],
        ]);

        $this->withSession(['exact_stop.connection_id' => $connection->id])
            ->post('/exact/stop')
            ->assertRedirect(route('exact.stop.done'));

        Bus::assertDispatched(
            ForwardConnectionRevokedToConsumerJob::class,
            fn (ForwardConnectionRevokedToConsumerJob $job): bool => $job->revokedConnection->is($connection)
                && $job->source === 'exact_app_center',
        );

        Mail::assertQueued(
            ConnectionDeprovisioned::class,
            fn (ConnectionDeprovisioned $mail): bool => $mail->revokedConnection->is($connection)
                && $mail->hasTo(config('mail.notify_address')),
        );

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame('exact', $event->provider);
        $this->assertSame('deprovision', $event->topic);
        $this->assertSame('revoked', $event->action);
        $this->assertSame(InboundWebhookRecorder::OUTCOME_PROCESSED, $event->outcome);
        $this->assertSame(InboundWebhookRecorder::FANOUT_DISPATCHED, $event->fanout_status);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertSame($consumer->id, $event->consumer_id);
    }

    public function test_post_marks_fanout_skipped_without_consumer_callback(): void
    {
        Bus::fake([DeleteExactWebhookSubscriptionsJob::class, ForwardConnectionRevokedToConsumerJob::class]);
        Mail::fake();
        $connection = $this->activeExactConnection();

        $this->withSession(['exact_stop.connection_id' => $connection->id])
            ->post('/exact/stop')
            ->assertRedirect(route('exact.stop.done'));

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame(InboundWebhookRecorder::FANOUT_SKIPPED, $event->fanout_status);
    }

    public function test_unmatched_user_id_is_audited_as_unknown_tenant(): void
    {
        $this->get('/exact/stop?UserId=00000000-0000-0000-0000-000000000000')
            ->assertOk();

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame('exact', $event->provider);
        $this->assertSame('deprovision', $event->topic);
        $this->assertSame(InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT, $event->outcome);
        $this->assertNull($event->connection_id);
    }

    public function test_visit_without_user_id_is_not_audited(): void
    {
        $this->get('/exact/stop')->assertOk();

        $this->assertSame(0, InboundWebhookEvent::query()->count());
    }

    public function test_post_without_session_redirects_back_without_revoking(): void
    {
        $connection = $this->activeExactConnection();

        $this->post('/exact/stop')
            ->assertRedirect(route('exact.stop'));

        $this->assertSame('active', $connection->refresh()->status);
    }

    public function test_post_with_stale_session_for_revoked_connection_redirects_back(): void
    {
        $connection = $this->activeExactConnection([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->withSession(['exact_stop.connection_id' => $connection->id])
            ->post('/exact/stop')
            ->assertRedirect(route('exact.stop'));
    }

    public function test_done_page_renders(): void
    {
        $this->get('/exact/stop/klaar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('exact/stop')
                ->where('state', 'done'));
    }

    public function test_stop_pages_send_noindex_header(): void
    {
        $this->get('/exact/stop')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}
