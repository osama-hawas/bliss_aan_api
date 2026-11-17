<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Slice 5b: persist offer expiry (reads already treat a past valid_until as expired).
Schedule::command('offers:expire')->everyFiveMinutes()->withoutOverlapping();

// Slice 8 (D136): delete read notifications older than 180 days.
Schedule::command('notifications:prune')->daily()->withoutOverlapping();

// Slice 20 (S20, D305): start scheduled broadcast campaigns.
Schedule::command('broadcasts:dispatch-due')->everyMinute()->withoutOverlapping();
