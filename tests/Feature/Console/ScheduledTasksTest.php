<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => $event->command ?? $event->getSummaryForDisplay())
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
}
