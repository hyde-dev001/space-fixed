<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('SHOP_OWNER_CANONICAL_SHELL_ENABLED', false),
    'allowlisted_shop_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('SHOP_OWNER_CANONICAL_SHELL_SHOP_IDS', '')),
    ), static fn (int $id): bool => $id > 0)),
];
