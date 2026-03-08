<?php

use App\Jobs\AutoApproveLowValuePRsJob;
use App\Jobs\CheckOverduePurchaseOrdersJob;
use App\Jobs\GenerateProcurementReportJob;
use App\Jobs\UpdateSupplierMetricsJob;
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

// Procurement: Check for overdue purchase orders
// Runs daily at 8:00 AM
Schedule::job(new CheckOverduePurchaseOrdersJob())
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Procurement: Update supplier performance metrics
// Runs nightly at 2:00 AM
Schedule::job(new UpdateSupplierMetricsJob())
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

// Procurement: Auto-approve low-value purchase requests
// Runs every hour during business hours (8 AM - 6 PM)
Schedule::job(new AutoApproveLowValuePRsJob())
    ->hourly()
    ->between('8:00', '18:00')
    ->weekdays()
    ->withoutOverlapping()
    ->onOneServer();

// Procurement: Generate monthly procurement report
// Runs on the 1st day of each month at 6:00 AM
Schedule::job(new GenerateProcurementReportJob())
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping()
    ->onOneServer();

