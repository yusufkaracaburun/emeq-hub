<?php

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
