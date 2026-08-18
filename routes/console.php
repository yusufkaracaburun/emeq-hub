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

Schedule::command('books:generate-recurring-invoices')->dailyAt('06:00');

Schedule::command('model:prune', [
    '--model' => [PassThroughCall::class, InboundWebhookEvent::class, IdempotencyKey::class],
])->dailyAt('03:00');
Schedule::command('queue:prune-failed', ['--hours' => 168])->weekly();
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->dailyAt('03:10');
Schedule::command('oauth:prune-pending')->dailyAt('03:15');
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('backup:clean')->dailyAt('01:30');
Schedule::command('backup:run --only-db')->dailyAt('01:45');
Schedule::command('backup:monitor')->dailyAt('02:00');
