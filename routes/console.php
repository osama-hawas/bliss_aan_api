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

// Slice 10: settle open payments, expire unpaid attempts (D191), retry pending fulfilments and refunds.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
// S9b (Slice 9b store orders): expire unpaid orders; poll the carrier for in-transit shipments.
Schedule::command('store-orders:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shipments:sync')->everyThirtyMinutes()->withoutOverlapping();
// Slice 11 (S11): back-fill provider earnings and refund adjustments (slice-11-contract.md §4).
Schedule::command('payouts:record-earnings')->everyFifteenMinutes()->withoutOverlapping();
// Slice 17 (D265): close resolved tickets after support.auto_close_days (null = off, no-op).
Schedule::command('support:auto-close')->daily()->withoutOverlapping();
// Slice 20 (S20, D305): start scheduled broadcast campaigns.
Schedule::command('broadcasts:dispatch-due')->everyMinute()->withoutOverlapping();
