<?php

$allowedRegistrationProvinces = json_decode((string) env('ALLOWED_REGISTRATION_PROVINCES', '["Cavite"]'), true);

if (!is_array($allowedRegistrationProvinces) || empty($allowedRegistrationProvinces)) {
    $allowedRegistrationProvinces = ['Cavite'];
}

$allowedRegistrationProvinces = array_values(array_filter(array_map(
    static fn ($province) => is_string($province) ? trim($province) : '',
    $allowedRegistrationProvinces,
)));

return [
    'allow_manual_review_fallback' => false,

    'allowed_registration_provinces' => $allowedRegistrationProvinces,

    'provinces' => [
        'Cavite' => [
            'bounds' => [
                'min_lat' => 14.05,
                'max_lat' => 14.52,
                'min_lng' => 120.55,
                'max_lng' => 121.05,
            ],

            'polygon' => [
                ['lat' => 14.4447, 'lng' => 120.5853],
                ['lat' => 14.4300, 'lng' => 120.6640],
                ['lat' => 14.4590, 'lng' => 120.7760],
                ['lat' => 14.4770, 'lng' => 120.8920],
                ['lat' => 14.4380, 'lng' => 121.0180],
                ['lat' => 14.3610, 'lng' => 121.0410],
                ['lat' => 14.2550, 'lng' => 120.9850],
                ['lat' => 14.1550, 'lng' => 120.9720],
                ['lat' => 14.0950, 'lng' => 120.9250],
                ['lat' => 14.0720, 'lng' => 120.8420],
                ['lat' => 14.0850, 'lng' => 120.7140],
                ['lat' => 14.1400, 'lng' => 120.6150],
                ['lat' => 14.2550, 'lng' => 120.5630],
                ['lat' => 14.3700, 'lng' => 120.5600],
            ],

            'allowed_city_names' => [
                'Alfonso',
                'Amadeo',
                'Bacoor',
                'Carmona',
                'Cavite City',
                'Dasmariñas',
                'General Emilio Aguinaldo',
                'General Mariano Alvarez',
                'General Trias',
                'Imus',
                'Indang',
                'Kawit',
                'Magallanes',
                'Maragondon',
                'Mendez',
                'Naic',
                'Noveleta',
                'Rosario',
                'Silang',
                'Tagaytay',
                'Tanza',
                'Ternate',
                'Trece Martires',
            ],

            'address_keywords' => [
                'cavite',
                'alfonso',
                'amadeo',
                'bacoor',
                'carmona',
                'cavite city',
                'dasmariñas',
                'dasmarinas',
                'general emilio aguinaldo',
                'general mariano alvarez',
                'gma',
                'general trias',
                'imus',
                'indang',
                'kawit',
                'magallanes',
                'maragondon',
                'mendez',
                'naic',
                'noveleta',
                'rosario',
                'silang',
                'tagaytay',
                'tanza',
                'ternate',
                'trece martires',
            ],
        ],
    ],
];