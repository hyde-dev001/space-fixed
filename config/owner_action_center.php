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
    'per_page' => 20,
    'max_per_page' => 50,
    'max_page' => 100,
    'home_limit' => 5,
];
