<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto clock-out employees at shop closing time
// Runs every 15 minutes to check if shop has closed
Schedule::command('attendance:auto-clockout')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
