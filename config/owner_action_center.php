<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('SHOP_OWNER_ACTION_CENTER_ENABLED', false),
    'allowlisted_shop_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('SHOP_OWNER_ACTION_CENTER_SHOP_IDS', '')),
    ), static fn (int $id): bool => $id > 0)),
    'coverage' => [
        'refunds' => (bool) env('SHOP_OWNER_ACTION_CENTER_REFUNDS_ENABLED', true),
        'expenses' => (bool) env('SHOP_OWNER_ACTION_CENTER_EXPENSES_ENABLED', true),
        'purchase_requests' => (bool) env('SHOP_OWNER_ACTION_CENTER_PURCHASE_REQUESTS_ENABLED', true),
    ],
    'buckets' => [
        'urgent_exceptions' => [
            'enabled' => (bool) env('SHOP_OWNER_ACTION_CENTER_URGENT_EXCEPTIONS_ENABLED', false),
            'coverage' => [
                'compliance' => (bool) env('SHOP_OWNER_ACTION_CENTER_COMPLIANCE_ENABLED', false),
                'refunds' => (bool) env('SHOP_OWNER_ACTION_CENTER_FAILED_REFUNDS_ENABLED', false),
                'logistics' => (bool) env('SHOP_OWNER_ACTION_CENTER_LOGISTICS_EXCEPTIONS_ENABLED', false),
            ],
        ],
    ],
    'per_page' => 20,
    'max_per_page' => 50,
    'max_page' => 100,
    'home_limit' => 5,
];
