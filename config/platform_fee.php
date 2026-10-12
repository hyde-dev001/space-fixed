<?php

return [
    'effective_from' => null,
    'terms_version' => null,
    'terms_text' => null,
    'defaults' => [
        'platform_fee_rate' => '5.000000',
        'platform_fee_vat_enabled' => true,
        'platform_fee_vat_rate' => '12.000000',
        'balance_limit' => '25000.00',
        'warning_threshold_percentage' => '80.0000',
        'critical_threshold_percentage' => '90.0000',
        'enforcement_enabled' => true,
    ],
    'shop_types' => [
        'individual' => [
            'balance_limit' => '25000.00',
        ],
        'business' => [
            'balance_limit' => '50000.00',
        ],
    ],
    'reliability' => [
        'window_days' => 180,
        'version' => 'v1',
        'weights' => [
            'payment_history' => 35,
            'settlement_timeliness' => 25,
            'marketplace_history' => 15,
            'refund_performance' => 10,
            'dispute_rate' => 10,
            'account_activity' => 5,
        ],
        'tiers' => [
            ['key' => 'base', 'minimum_score' => 0, 'recommended_limit' => null],
            ['key' => 'tier_2', 'minimum_score' => 50, 'recommended_limit' => '35000.00'],
            ['key' => 'tier_3', 'minimum_score' => 70, 'recommended_limit' => '50000.00'],
            ['key' => 'highest', 'minimum_score' => 85, 'recommended_limit' => '75000.00'],
        ],
    ],
];
