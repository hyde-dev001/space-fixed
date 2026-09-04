<?php

return [
    'enabled' => (bool) env('LOGISTICS_LIVE_TRACKING_ENABLED', false),

    'rider' => [
        'moving_interval_seconds' => (int) env('LOGISTICS_TRACKING_MOVING_INTERVAL', 5),
        'stationary_interval_seconds' => (int) env('LOGISTICS_TRACKING_STATIONARY_INTERVAL', 30),
        'hidden_interval_seconds' => (int) env('LOGISTICS_TRACKING_HIDDEN_INTERVAL', 60),
    ],

    'viewer_interval_seconds' => (int) env('LOGISTICS_TRACKING_VIEWER_INTERVAL', 5),
    'stale_after_seconds' => (int) env('LOGISTICS_TRACKING_STALE_AFTER', 90),

    'gps' => [
        'max_accuracy_m' => (float) env('LOGISTICS_TRACKING_MAX_ACCURACY', 50000),
        'max_record_age_seconds' => (int) env('LOGISTICS_TRACKING_MAX_RECORD_AGE', 120),
        'max_future_seconds' => (int) env('LOGISTICS_TRACKING_MAX_FUTURE', 60),
        'max_implied_speed_mps' => (float) env('LOGISTICS_TRACKING_MAX_IMPLIED_SPEED', 100),
    ],

    'routing' => [
        'enabled' => (bool) env('LOGISTICS_TRACKING_ROUTING_ENABLED', true),
        'provider' => env('LOGISTICS_TRACKING_ROUTING_PROVIDER', 'osrm'),
        'base_url' => env('LOGISTICS_TRACKING_ROUTING_BASE_URL', 'https://router.project-osrm.org'),
        'timeout_seconds' => (float) env('LOGISTICS_TRACKING_ROUTING_TIMEOUT', 3),
        'fallback_to_direct' => (bool) env('LOGISTICS_TRACKING_ROUTING_FALLBACK_DIRECT', true),
        'eta_speed_mps' => (float) env('LOGISTICS_TRACKING_ETA_SPEED_MPS', 8.33),
        'cache_seconds' => (int) env('LOGISTICS_TRACKING_ROUTING_CACHE', 60),
    ],

    'rate_limits' => [
        'location_updates_per_minute' => (int) env('LOGISTICS_TRACKING_LOCATION_RATE', 20),
        'viewer_requests_per_minute' => (int) env('LOGISTICS_TRACKING_VIEWER_RATE', 20),
    ],
];
