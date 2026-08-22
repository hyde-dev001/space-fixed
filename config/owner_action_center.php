<?php

declare(strict_types=1);

return [
    // Thesis mode keeps the Action Center visible without deployment-specific env changes.
    'enabled' => true,
    'allowlisted_shop_ids' => [],
    'coverage' => [
        'refunds' => true,
        'prices' => true,
        'payslips' => true,
        'salary_changes' => true,
        'expenses' => true,
        'purchase_requests' => true,
        'repair_rejections' => true,
    ],
    'buckets' => [
        'urgent_exceptions' => [
            'enabled' => true,
            'coverage' => [
                'compliance' => true,
                'refunds' => true,
                'logistics' => true,
            ],
        ],
        'waiting_on_others' => [
            'enabled' => true,
            'coverage' => [
                'compliance' => true,
                'refunds' => true,
                'logistics' => true,
            ],
        ],
    ],
    'per_page' => 20,
    'max_per_page' => 50,
    'max_page' => 100,
    'home_limit' => 3,
];
