<?php

return [
    // Default eligibility window for cancellation/refund in minutes (7 days).
    'cancellation_refund_window_minutes' => env('ORDER_CANCELLATION_REFUND_WINDOW_MINUTES', 10080),

    // Controls split-leg mixed refund behavior for repair module.
    'repair_split_refund_enabled' => env('REPAIR_SPLIT_REFUND_ENABLED', true),
];
