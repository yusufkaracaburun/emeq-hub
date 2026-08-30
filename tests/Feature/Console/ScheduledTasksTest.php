<?php

namespace Tests\Feature\Console;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sentRequests = [];

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => $event->command ?? $event->getSummaryForDisplay())
            ->all();
    }

    private function rebuildScheduleWithHeartbeat(?string $heartbeatUrl): Schedule
    {
        config(['services.betterstack.heartbeat_url' => $heartbeatUrl]);

        Facade::clearResolvedInstance(Schedule::class);
        app()->forgetInstance(Schedule::class);

        $schedule = app(Schedule::class);
        require base_path('routes/console.php');

        return $schedule;
    }

    private function fakeSchedulerHttpClient(): void
    {
        $this->sentRequests = [];

        $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(200)]));
        $stack->push(Middleware::history($this->sentRequests));

        app()->instance(GuzzleClient::class, new GuzzleClient(['handler' => $stack]));
    }

    private function horizonSnapshotEvent(Schedule $schedule): Event
    {
        $event = collect($schedule->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'horizon:snapshot'));

        $this->assertNotNull($event, "Verwachtte een gescheduled 'horizon:snapshot'-command.");

        return $event;
    }

    /** @return list<string> */
    private function pingedUrls(): array
    {
        return collect($this->sentRequests)
            ->map(fn (array $transaction): string => (string) $transaction['request']->getUri())
            ->all();
    }

    public function test_retention_and_cleanup_commands_are_scheduled(): void
    {
        $commands = collect($this->scheduledCommands());

        foreach (['model:prune', 'queue:prune-failed', 'sanctum:prune-expired', 'oauth:prune-pending', 'horizon:snapshot'] as $needle) {
            $this->assertTrue(
                $commands->contains(fn (string $command): bool => str_contains($command, $needle)),
                "Verwachtte een gescheduled '{$needle}'-command."
            );
        }
    }

    public function test_horizon_snapshot_pings_the_heartbeat_when_it_succeeds(): void
    {
        $schedule = $this->rebuildScheduleWithHeartbeat('https://uptime.test/heartbeat/token');
        $this->fakeSchedulerHttpClient();

        $this->horizonSnapshotEvent($schedule)->finish(app(), 0);

        $this->assertSame(['https://uptime.test/heartbeat/token'], $this->pingedUrls());
    }

    public function test_horizon_snapshot_does_not_ping_the_heartbeat_when_it_fails(): void
    {
        $schedule = $this->rebuildScheduleWithHeartbeat('https://uptime.test/heartbeat/token');
        $this->fakeSchedulerHttpClient();

        $this->horizonSnapshotEvent($schedule)->finish(app(), 1);

        $this->assertSame([], $this->pingedUrls());
    }

    public function test_horizon_snapshot_does_not_ping_when_no_heartbeat_is_configured(): void
    {
        $schedule = $this->rebuildScheduleWithHeartbeat(null);
        $this->fakeSchedulerHttpClient();

        $this->horizonSnapshotEvent($schedule)->finish(app(), 0);

        $this->assertSame([], $this->pingedUrls());
    }

    public function test_backup_commands_are_restricted_to_production(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'backup:'));

        $this->assertCount(3, $events, 'Verwachtte 3 gescheduled backup:*-commands.');

        $events->each(function (Event $event): void {
            $this->assertSame(
                ['production'],
                $event->environments,
                "'{$event->command}' moet enkel in production draaien (lokaal geen 24/7-container, dus MaximumAgeInDays-health-check faalt vals-positief)."
            );
        });
    }
}
