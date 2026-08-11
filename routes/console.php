<?php

use App\Models\IdempotencyKey;
use App\Models\InboundWebhookEvent;
use App\Models\PassThroughCall;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Books: genereer dagelijks de due terugkerende verkoopfacturen (concept).
// Vereist een draaiend `schedule:run`/`schedule:work`-proces in de stack;
// handmatig triggeren kan via de "Genereer nu"-actie of `books:generate-recurring-invoices`.
Schedule::command('books:generate-recurring-invoices')->dailyAt('06:00');

// Data-retentie + opschoning (VPS-hardening). Houdt de audit-tabellen en Redis-
// job-records begrensd; venster in config/hub.php (issue #41 zet het beleid).
Schedule::command('model:prune', [
    '--model' => [PassThroughCall::class, InboundWebhookEvent::class, IdempotencyKey::class],
])->dailyAt('03:00');
Schedule::command('queue:prune-failed', ['--hours' => 168])->weekly();
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->dailyAt('03:10');
Schedule::command('oauth:prune-pending')->dailyAt('03:15');
// Vult de Horizon-metrics-grafiek; trim_snapshots ruimt oude af.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Encrypted + gemonitorde DB-backup (spatie/laravel-backup) naar de lokale
// `backups`-disk; off-site via de OVH Automated Backup-snapshot. Retentie +
// health-check in config/backup.php. De pre-deploy pg_dump blijft als
// dependency-vrije pre-migratie-safety.
Schedule::command('backup:clean')->dailyAt('01:30');
Schedule::command('backup:run --only-db')->dailyAt('01:45');
Schedule::command('backup:monitor')->dailyAt('02:00');
