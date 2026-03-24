<?php

return [
    'procurement' => [
        'category' => 'Procurement',
        'status' => 'approved',
        'reference_prefix' => 'PROC-EXP-',
        'description_template' => 'Auto-generated from Purchase Order: :reference',
        'meta_source' => 'purchase_order',
    ],

    'payroll' => [
        'category' => 'Payroll',
        'status' => 'submitted',
        'reference_prefix' => 'PAY-EXP-',
        'description_template' => 'Auto-generated from Payroll: :employee_name (:payroll_period)',
        'meta_source' => 'payroll',
    ],

    'refund' => [
        'category' => 'Refund',
        'status' => 'submitted',
        'reference_prefix' => 'REF-EXP-',
        'description_template' => 'Auto-generated from Refund: :reference',
        'meta_source' => 'refund',
    ],
];