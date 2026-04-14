<?php

return [
    // Per-user limit for customer->shop reports in a single day.
    // Set to 0 to disable this guard.
    'shop_report_daily_limit' => (int) env('SHOP_REPORT_DAILY_LIMIT', 3),

    // Signed suspension appeal link validity window (in hours).
    'suspension_appeal_link_hours' => (int) env('SUSPENSION_APPEAL_LINK_HOURS', 168),
];
