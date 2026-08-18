<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class BackupConfigTest extends TestCase
{
    public function test_backup_targets_the_backups_disk_with_encryption(): void
    {
        $this->assertSame('emeq-hub', config('backup.backup.name'));
        $this->assertSame(['backups'], config('backup.backup.destination.disks'));
        $this->assertSame('default', config('backup.backup.encryption'));
        $this->assertSame('local', config('filesystems.disks.backups.driver'));
    }

    public function test_backup_commands_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) ($event->command ?? ''))
            ->implode("\n");

        $this->assertStringContainsString('backup:run --only-db', $commands);
        $this->assertStringContainsString('backup:clean', $commands);
        $this->assertStringContainsString('backup:monitor', $commands);
    }
}
