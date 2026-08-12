<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('logistics:monitor-overdue')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto clock-out employees at shop closing time
// Runs every 15 minutes to check if shop has closed
Schedule::command('attendance:auto-clockout')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Auto-mark absent / on_leave for employees with no clock-in on a working day
// Runs daily at 00:05 (just after midnight) to process the completed day
Schedule::command('attendance:mark-absent')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer();

// Inventory Management: Check for low stock and overdue orders
// Runs daily at 9:00 AM
Schedule::command('inventory:check-alerts')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

// Payment lifecycle: expire stale unpaid sessions and release reservations.
Schedule::command('payments:expire-stale')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Premium subscriptions: process due auto-renewals and create renewal checkout links.
Schedule::command('subscriptions:process-premium-renewals')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Product discount schedule lifecycle: apply start dates and auto-revert after end dates.
Schedule::command('products:process-discount-schedules')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Suspension appeals own routine expiry detection; queue pages remain read-only.
Schedule::command('suspension-appeals:expire')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
