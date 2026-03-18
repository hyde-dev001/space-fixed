<?php

return [
    'scope' => [
        'country' => 'Philippines',
        'province' => 'Cavite',
        'applies_to' => ['all_shops_in_cavite'],
    ],

    'cycle' => [
        'type' => 'semi_monthly',
        'pay_days' => [15, 30],
        'cutoff_windows' => [
            [
                'name' => 'cutoff_1',
                'from_day' => 1,
                'to_day' => 15,
                'pay_day' => 30,
            ],
            [
                'name' => 'cutoff_2',
                'from_day' => 16,
                'to_day' => 'end_of_month',
                'pay_day' => 15,
            ],
        ],
        'non_business_day_rule' => 'move_to_previous_business_day',
    ],

    'base_pay_table' => [
        'Store Manager' => 65000.00,
        'Finance Officer' => 45000.00,
        'HR Specialist' => 42000.00,
        'Customer Relations Officer' => 38000.00,
        'Inventory Manager' => 48000.00,
        'Procurement Manager' => 50000.00,
        'Sales Associate' => 28000.00,
        'Shoe Repair Technician' => 35000.00,
    ],

    'maker_checker' => [
        'maker' => 'HR',
        'checker' => 'Finance',
        'final_approver' => 'Shop Owner',
        'require_checker_before_release' => true,
        'require_final_approver_before_release' => true,
    ],

    'salary_change' => [
        'minor_threshold_percent' => 5.0,
        'required_fields' => [
            'salary_effective_date',
            'salary_change_reason',
            'salary_approved_by',
        ],
        'matrix' => [
            'new_hire_rate_setup' => [
                'proposed_by' => 'HR',
                'approved_by' => 'Shop Owner',
            ],
            'minor_adjustment' => [
                'max_percent' => 5.0,
                'proposed_by' => 'HR',
                'checked_by' => 'Finance',
                'approved_by' => 'Shop Owner',
            ],
            'major_adjustment' => [
                'min_percent_exclusive' => 5.0,
                'recommended_by' => ['HR', 'Finance'],
                'approved_by' => 'Shop Owner',
            ],
        ],
    ],

    'attendance_policy' => [
        // If true, basic pay is prorated by paid days (attendance + paid leave)
        // instead of granting full monthly basic then deducting absences.
        'no_work_no_pay' => true,

        // If true, approved leave days are counted as paid day units.
        'paid_leave_counts_as_worked' => true,
    ],
];
