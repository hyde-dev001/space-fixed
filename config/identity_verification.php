<?php

return [
    'upload' => [
        'disk' => 'local',
        'max_kilobytes' => 5120,
        'mime_extensions' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],

    'ocr' => [
        'max_text_length' => 12000,
    ],

    'thresholds' => [
        'pass_ocr_confidence' => 0.80,
        'pass_classification_confidence' => 0.80,
    ],

    'common' => [
        'minimum_name_tokens' => 2,
        'name_match_minimum_ratio' => 0.60,
    ],

    // These definitions are the only source of document-specific screening rules.
    'documents' => [
        'national_id' => [
            'label' => 'National ID',
            'requires_back' => true,
            'required_slots' => ['front', 'back'],
            'formats' => [
                'physical_card' => [
                    'required_slots' => ['front', 'back'],
                ],
                'digital_image' => [
                    'required_slots' => ['front', 'back'],
                ],
            ],
            'signals' => [
                'philippine identification card',
                'pambansang pagkakakilanlan',
                'philsys',
                'national id',
                'republic of the philippines',
            ],
            'required_signal_groups' => [
                [
                    'philippine identification card',
                    'pambansang pagkakakilanlan',
                    'national id',
                ],
                [
                    'philsys',
                    'republic of the philippines',
                ],
            ],
            'required_fields' => ['name_detected', 'birth_date_detected', 'id_number_detected', 'address_detected'],
            'required_structures' => ['qr'],
            'id_number_patterns' => [
                '/(?<![\p{L}\p{N}])\d{16}(?![\p{L}\p{N}])/u',
            ],
            'minimum_signals' => 2,
            'upload_guidance' => [
                'instruction' => 'Physical or digital PhilID: upload clear front and back images in landscape orientation. Rotate portrait screenshots before uploading.',
                'visual_checks' => ['photo', 'complete_document', 'qr'],
            ],
            'front' => [
                'anchor_groups' => [
                    ['philippine identification card', 'pambansang pagkakakilanlan', 'national id'],
                    ['philsys', 'republic of the philippines'],
                ],
                'supporting_fields' => ['name_detected', 'birth_date_detected', 'id_number_detected'],
            ],
            'back' => [
                'anchor_groups' => [
                    ['philsys', 'philippine identification', 'pambansang pagkakakilanlan'],
                ],
                'supporting_signals' => ['qr', 'secondary_demographic_data', 'document_structure'],
                'allow_low_ocr' => true,
            ],
        ],
        'drivers_license' => [
            'label' => "Driver's License",
            'requires_back' => true,
            'required_slots' => ['front', 'back'],
            'signals' => [
                "driver's license",
                'drivers license',
                'driver license',
                'land transportation office',
                'lto',
                'republic of the philippines',
                'philippines',
            ],
            'required_signal_groups' => [
                [
                    "driver's license",
                    'drivers license',
                    'driver license',
                ],
                [
                    'land transportation office',
                    'lto',
                ],
                [
                    'republic of the philippines',
                    'philippines',
                ],
            ],
            'required_fields' => [
                'name_detected',
                'birth_date_detected',
                'id_number_detected',
                'issue_date_detected',
                'expiration_date_detected',
                'expiration_date_valid',
                'address_detected',
            ],
            'minimum_signals' => 2,
            'id_number_patterns' => [
                '/(?<![\p{L}\p{N}])[A-Z]{1,3}\d{5,12}(?![\p{L}\p{N}])/iu',
            ],
            'upload_guidance' => [
                'instruction' => "Physical LTO Driver's License: upload a clear front and back image. Replace screenshots or unclear images with a readable photo of the ID.",
                'visual_checks' => ['photo', 'complete_document', 'signature'],
            ],
            'front' => [
                'anchor_groups' => [
                    ['land transportation office', 'lto'],
                    ['driver\'s license', 'drivers license', 'driver license'],
                    ['republic of the philippines', 'philippines'],
                ],
                'supporting_fields' => ['name_detected', 'birth_date_detected', 'id_number_detected', 'expiration_date_detected'],
            ],
            'back' => [
                'anchor_groups' => [
                    ['dl code', 'driving conditions', 'restriction', 'restrictions'],
                ],
                'supporting_signals' => ['license_conditions', 'secondary_license_text', 'document_structure'],
                'allow_low_ocr' => true,
            ],
        ],
        'passport' => [
            'label' => 'Philippine Passport',
            'requires_back' => false,
            'required_slots' => ['biodata'],
            'signals' => [
                'passport',
                'republic of the philippines',
                'republika ng pilipinas',
                'phl',
            ],
            'required_signal_groups' => [
                ['passport'],
                [
                    'republic of the philippines',
                    'republika ng pilipinas',
                ],
            ],
            'required_fields' => [
                'name_detected',
                'birth_date_detected',
                'id_number_detected',
                'issue_date_detected',
                'expiration_date_detected',
                'expiration_date_valid',
                'mrz_valid',
            ],
            'minimum_signals' => 2,
            'required_structures' => ['mrz'],
            'id_number_patterns' => [
                '/(?<![\p{L}\p{N}])[A-Z]\d{7,8}(?![\p{L}\p{N}])/iu',
            ],
            'upload_guidance' => [
                'instruction' => 'Upload the passport biodata page only, including the complete machine-readable zone (MRZ).',
                'visual_checks' => ['photo', 'complete_document', 'mrz'],
            ],
            'biodata' => [
                'anchor_groups' => [
                    ['passport', 'pasaporte'],
                    ['republic of the philippines', 'republika ng pilipinas', 'phl'],
                ],
                'supporting_signals' => ['mrz', 'passport_number', 'birth_date', 'expiry_date'],
            ],
        ],
        'umid' => [
            'label' => 'UMID',
            'requires_back' => false,
            'required_slots' => ['front'],
            'signals' => [
                'umid',
                'unified multipurpose identification',
                'unified multi purpose identification',
                'unified multi-purpose identification',
                'republic of the philippines',
                'sss',
                'gsis',
                'philhealth',
                'pag-ibig',
                'common reference number',
            ],
            'required_signal_groups' => [
                [
                    'umid',
                    'unified multipurpose identification',
                    'unified multi purpose identification',
                    'unified multi-purpose identification',
                    'crn',
                    'common reference number',
                ],
                [
                    'sss',
                    'gsis',
                    'philhealth',
                    'pag-ibig',
                    'republic of the philippines',
                ],
            ],
            'required_fields' => ['name_detected', 'birth_date_detected', 'id_number_detected'],
            'minimum_signals' => 2,
            'id_number_patterns' => [
                '/\b(?:crn|common reference number)\s*[:#-]?\s*\d{9,16}\b/iu',
            ],
            'upload_guidance' => [
                'instruction' => 'Upload one clear landscape image of the complete UMID front. The back is not required. Automated screening checks document plausibility; it does not verify chips, holograms, or other authenticity features.',
                'visual_checks' => ['photo', 'complete_document', 'security_features'],
            ],
            'front' => [
                'anchor_groups' => [
                    ['umid', 'unified multipurpose identification', 'unified multi purpose identification', 'unified multi-purpose identification', 'crn', 'common reference number'],
                    ['sss', 'gsis', 'philhealth', 'pag-ibig', 'republic of the philippines'],
                ],
                'supporting_fields' => ['name_detected', 'birth_date_detected', 'id_number_detected'],
            ],
        ],
    ],

    'obvious_non_document_signals' => [
        'receipt',
        'invoice',
        'subtotal',
        'total amount',
        'thank you for your purchase',
        'order number',
        'novelty',
        'specimen',
        'not a real id',
        'fictional',
        'mockup',
        'bikini bottom',
        'spongebob',
        'may be accessed via',
        'for illustration purposes',
        'this guide shows',
        'document features',
        'is made of polycarbonate',
        'qr code that may be scanned',
    ],
];
