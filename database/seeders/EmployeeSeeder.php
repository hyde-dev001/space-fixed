<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\LeaveRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Carbon\Carbon;

class EmployeeSeeder extends Seeder
{
    private ?array $usersRoleEnumOptions = null;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all shop owners
        $shopOwners = ShopOwner::all();

        foreach ($shopOwners as $shopOwner) {
            $this->createEmployeesForShopOwner($shopOwner);
        }
    }

    /**
     * Create employees for a specific shop owner based on their business type
     */
    private function createEmployeesForShopOwner(ShopOwner $shopOwner): void
    {
        $businessType = $shopOwner->business_type;
        $isRetailOnly = $businessType === 'retail';
        $basePayTable = config('payroll_governance.base_pay_table', []);

        // Common employees for all business types
        $commonEmployees = [
            // Manager - Full access
            [
                'first_name' => 'Michael',
                'last_name' => 'Anderson',
                'email' => "manager.{$shopOwner->id}@solespace.com",
                'position' => 'Store Manager',
                'department' => 'Manager',
                'salary' => 2500.00,
                'phone' => '+639171110001',
            ],
            // Finance Staff
            [
                'first_name' => 'Sarah',
                'last_name' => 'Johnson',
                'email' => "finance.{$shopOwner->id}@solespace.com",
                'position' => 'Finance Officer',
                'department' => 'Finance',
                'salary' => 1730.77,
                'phone' => '+639172220001',
            ],
            // HR Staff
            [
                'first_name' => 'David',
                'last_name' => 'Williams',
                'email' => "hr.{$shopOwner->id}@solespace.com",
                'position' => 'HR Specialist',
                'department' => 'HR',
                'salary' => 1615.38,
                'phone' => '+639173330001',
            ],
            // CRM Staff
            [
                'first_name' => 'Emily',
                'last_name' => 'Davis',
                'email' => "crm.{$shopOwner->id}@solespace.com",
                'position' => 'Customer Relations Officer',
                'department' => 'CRM',
                'salary' => 1461.54,
                'phone' => '+639174440001',
            ],
            // Cashier Staff
            [
                'first_name' => 'Kevin',
                'last_name' => 'Lopez',
                'email' => "cashier.{$shopOwner->id}@solespace.com",
                'position' => 'Cashier',
                'department' => 'Cashier',
                'salary' => 1076.92,
                'phone' => '+639179990001',
            ],
            // Inventory Manager
            [
                'first_name' => 'Robert',
                'last_name' => 'Martinez',
                'email' => "inventory.{$shopOwner->id}@solespace.com",
                'position' => 'Inventory Manager',
                'department' => 'Inventory Manager',
                'salary' => 1846.15,
                'phone' => '+639175550001',
            ],
            // Procurement Manager
            [
                'first_name' => 'Patricia',
                'last_name' => 'Reyes',
                'email' => "procurement.{$shopOwner->id}@solespace.com",
                'position' => 'Procurement Manager',
                'department' => 'Procurement Manager',
                'salary' => 1923.08,
                'phone' => '+639178880001',
            ],
            // General Staff
            [
                'first_name' => 'Jessica',
                'last_name' => 'Garcia',
                'email' => "staff.{$shopOwner->id}@solespace.com",
                'position' => 'Sales Associate',
                'department' => 'Staff',
                'salary' => 1076.92,
                'phone' => '+639176660001',
            ],
            [
                'first_name' => 'Daniel',
                'last_name' => 'Cruz',
                'email' => "logistics.dispatcher.{$shopOwner->id}@solespace.com",
                'position' => 'Logistics Dispatcher',
                'department' => 'Logistics Dispatcher',
                'salary' => 1076.92,
                'phone' => '+639180000001',
            ],
            [
                'first_name' => 'Marco',
                'last_name' => 'Santos',
                'email' => "logistics.rider.{$shopOwner->id}@solespace.com",
                'position' => 'Logistics Rider',
                'department' => 'Logistics Rider',
                'salary' => 1076.92,
                'phone' => '+639180000002',
            ],
            [
                'first_name' => 'Paolo',
                'last_name' => 'Mendoza',
                'email' => "logistics.rider2.{$shopOwner->id}@solespace.com",
                'position' => 'Logistics Rider',
                'department' => 'Logistics Rider',
                'salary' => 1076.92,
                'phone' => '+639180000003',
            ],
        ];

        // Add Repairer only if not retail-only business
        if (!$isRetailOnly) {
            $commonEmployees[] = [
                'first_name' => 'Thomas',
                'last_name' => 'Rodriguez',
                'email' => "repairer.{$shopOwner->id}@solespace.com",
                'position' => 'Shoe Repair Technician',
                'department' => 'Repairer',
                'salary' => 1346.15,
                'phone' => '+639177770001',
            ];
        }

        foreach ($commonEmployees as &$employeeData) {
            $position = $employeeData['position'] ?? '';
            if ($position !== '' && array_key_exists($position, $basePayTable)) {
                $employeeData['salary'] = round((float) $basePayTable[$position], 2);
            }

            // Salary is now seeded directly as daily rate.
            $employeeData['salary'] = round((float) $employeeData['salary'], 2);
        }
        unset($employeeData);

        // Get HR user for leave approvals
        $hrUser = User::where('role', 'HR')
            ->where('shop_owner_id', $shopOwner->id)
            ->first();

        // Create each employee
        foreach ($commonEmployees as $index => $employeeData) {
            $employee = Employee::updateOrCreate(
                ['email' => $employeeData['email']],
                [
                    'shop_owner_id' => $shopOwner->id,
                    'first_name' => $employeeData['first_name'],
                    'last_name' => $employeeData['last_name'],
                    'name' => $employeeData['first_name'] . ' ' . $employeeData['last_name'],
                    'email' => $employeeData['email'],
                    'password' => Hash::make($employeeData['email']),
                    'phone' => $employeeData['phone'],
                    'address' => $shopOwner->business_address,
                    'city' => $shopOwner->city_state,
                    'position' => $employeeData['position'],
                    'department' => $employeeData['department'],
                    'salary' => $employeeData['salary'],
                    'hire_date' => now()->subMonths(rand(6, 36)),
                    'status' => 'active',
                ]
            );

            // Map department to role format (uppercase for role column, proper case for Spatie)
            $roleMap = [
                'Manager' => ['role' => 'MANAGER', 'spatie' => 'Manager'],
                'Finance' => ['role' => 'FINANCE', 'spatie' => 'Finance'],
                'HR' => ['role' => 'HR', 'spatie' => 'HR'],
                'CRM' => ['role' => 'CRM', 'spatie' => 'CRM'],
                'Repairer' => ['role' => 'REPAIRER', 'spatie' => 'Repairer'],
                'Cashier' => ['role' => 'CASHIER', 'spatie' => 'Cashier'],
                'Inventory Manager' => ['role' => 'INVENTORY_MANAGER', 'spatie' => 'Inventory Manager'],
                'Procurement Manager' => ['role' => 'PROCUREMENT_MANAGER', 'spatie' => 'Procurement Manager'],
                'Staff' => ['role' => 'STAFF', 'spatie' => 'Staff'],
                'Logistics Dispatcher' => ['role' => 'LOGISTICS_DISPATCHER', 'spatie' => 'Logistics Dispatcher'],
                'Logistics Rider' => ['role' => 'LOGISTICS_RIDER', 'spatie' => 'Logistics Rider'],
            ];
            
            $department = $employeeData['department'];
            $mappedRole = $roleMap[$department] ?? ['role' => 'STAFF', 'spatie' => 'Staff'];
            $legacyRole = $this->resolveCompatibleLegacyRole($mappedRole['role']);
            
            // Create corresponding user account
            $user = User::updateOrCreate(
                ['email' => $employeeData['email']],
                [
                    'name' => $employeeData['first_name'] . ' ' . $employeeData['last_name'],
                    'first_name' => $employeeData['first_name'],
                    'last_name' => $employeeData['last_name'],
                    'email' => $employeeData['email'],
                    'phone' => $employeeData['phone'],
                    'address' => $shopOwner->business_address,
                    'password' => Hash::make($employeeData['email']),
                    'shop_owner_id' => $shopOwner->id, // Link user to shop owner
                    'role' => $legacyRole, // Keep enum-compatible users.role while Spatie role handles feature access.
                    'position' => $employeeData['position'],
                    'status' => 'active',
                    'force_password_change' => false, // Since we're using a known password
                ]
            );

            // Assign Spatie role based on department (use assignRole, not syncRoles)
            $user->roles()->detach();
            $user->assignRole($mappedRole['spatie']);

            $this->command->info("Created employee: {$employee->name} ({$mappedRole['role']}) for {$shopOwner->business_name}");

            // Create attendance records and leave requests for the employee
            $this->createAttendanceRecords($employee, $shopOwner);
            if ($hrUser) {
                $this->createLeaveRequests($employee, $shopOwner, $hrUser);
            }
        }

    }

        /**
         * Resolve enum-safe value for users.role across environments with schema drift.
         */
        private function resolveCompatibleLegacyRole(string $desiredRole): ?string
        {
            $desiredRole = strtoupper(trim($desiredRole));
            $allowed = $this->getUsersRoleEnumOptions();

            // If role column is not enum (or cannot be resolved), keep desired value.
            if (empty($allowed)) {
                return $desiredRole;
            }

            $candidates = match ($desiredRole) {
                // Cashier is represented by Spatie role; legacy users.role may not support this enum value.
                'CASHIER' => ['CASHIER', 'STAFF'],
                'INVENTORY_MANAGER' => ['INVENTORY_MANAGER', 'INVENTORY', 'STAFF'],
                'PROCUREMENT_MANAGER' => ['PROCUREMENT_MANAGER', 'STAFF'],
                default => [$desiredRole, 'STAFF'],
            };

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $allowed, true)) {
                    return $candidate;
                }
            }

            return $allowed[0] ?? null;
        }

        /**
         * Read users.role enum options from the current database schema.
         */
        private function getUsersRoleEnumOptions(): array
        {
            if (is_array($this->usersRoleEnumOptions)) {
                return $this->usersRoleEnumOptions;
            }

            if (!Schema::hasColumn('users', 'role')) {
                return $this->usersRoleEnumOptions = [];
            }

            if (DB::getDriverName() === 'sqlite') {
                return $this->usersRoleEnumOptions = [];
            }

            $columnRows = DB::select("SHOW COLUMNS FROM `users` LIKE 'role'");
            if (empty($columnRows)) {
                return $this->usersRoleEnumOptions = [];
            }

            $columnType = (string) (($columnRows[0]->Type ?? $columnRows[0]->type ?? ''));
            if (stripos($columnType, 'enum(') !== 0) {
                return $this->usersRoleEnumOptions = [];
            }

            preg_match_all("/'([^']+)'/", $columnType, $matches);
            $options = array_values(array_unique(array_map(static fn ($value) => strtoupper((string) $value), $matches[1] ?? [])));

            return $this->usersRoleEnumOptions = $options;
        }

    /**
         * Create attendance records for an employee (past 60 days)
         */
        private function createAttendanceRecords(Employee $employee, ShopOwner $shopOwner): void
        {
            $startDate = Carbon::now()->subDays(60);
            $endDate = Carbon::now();

            $date = $startDate->copy();
            while ($date->lte($endDate)) {
                $schedule = $this->resolveExpectedSchedule($shopOwner, $date);
                if ($schedule === null) {
                    $date->addDay();
                    continue;
                }

                $expectedCheckIn = $schedule['expected_check_in'];
                $expectedCheckOut = $schedule['expected_check_out'];
                $scheduledHours = $schedule['scheduled_hours'];
                $rand = rand(1, 100);

                if ($rand <= 80) {
                    $overtime = rand(1, 100) <= 30 ? rand(0, 3) : 0;
                    $checkIn = $date->copy()->setTimeFromTimeString($expectedCheckIn)->addMinutes(rand(0, 20));
                    $checkOut = $date->copy()->setTimeFromTimeString($expectedCheckOut)
                        ->addHours($overtime)
                        ->addMinutes(rand(0, 20));

                    AttendanceRecord::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'shop_owner_id' => $shopOwner->id,
                            'date' => $date->toDateString(),
                        ],
                        [
                            'status' => 'present',
                            'check_in_time' => $checkIn->toTimeString(),
                            'check_out_time' => $checkOut->toTimeString(),
                            'expected_check_in' => $expectedCheckIn,
                            'expected_check_out' => $expectedCheckOut,
                            'working_hours' => $scheduledHours,
                            'overtime_hours' => $overtime,
                            'is_late' => false,
                            'minutes_early_departure' => 0,
                        ]
                    );
                } elseif ($rand <= 90) {
                    AttendanceRecord::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'shop_owner_id' => $shopOwner->id,
                            'date' => $date->toDateString(),
                        ],
                        [
                            'status' => 'absent',
                            'check_in_time' => null,
                            'check_out_time' => null,
                            'expected_check_in' => $expectedCheckIn,
                            'expected_check_out' => $expectedCheckOut,
                            'working_hours' => 0,
                            'overtime_hours' => 0,
                            'is_late' => false,
                            'minutes_early_departure' => 0,
                        ]
                    );
                } elseif ($rand <= 95) {
                    $lateMinutes = rand(20, 75);
                    $checkIn = $date->copy()->setTimeFromTimeString($expectedCheckIn)->addMinutes($lateMinutes);
                    $checkOut = $date->copy()->setTimeFromTimeString($expectedCheckOut)->addMinutes(rand(0, 15));
                    $workedHours = max(round($scheduledHours - ($lateMinutes / 60), 2), 0.5);

                    AttendanceRecord::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'shop_owner_id' => $shopOwner->id,
                            'date' => $date->toDateString(),
                        ],
                        [
                            'status' => 'late',
                            'check_in_time' => $checkIn->toTimeString(),
                            'check_out_time' => $checkOut->toTimeString(),
                            'expected_check_in' => $expectedCheckIn,
                            'expected_check_out' => $expectedCheckOut,
                            'working_hours' => $workedHours,
                            'overtime_hours' => 0,
                            'is_late' => true,
                            'minutes_early_departure' => 0,
                        ]
                    );
                } else {
                    $halfDayHours = max(round($scheduledHours / 2, 2), 0.5);
                    $halfDayMinutes = (int) round($halfDayHours * 60);
                    $checkIn = $date->copy()->setTimeFromTimeString($expectedCheckIn)->addMinutes(rand(0, 10));
                    $checkOut = $checkIn->copy()->addMinutes($halfDayMinutes);

                    AttendanceRecord::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'shop_owner_id' => $shopOwner->id,
                            'date' => $date->toDateString(),
                        ],
                        [
                            'status' => 'half_day',
                            'check_in_time' => $checkIn->toTimeString(),
                            'check_out_time' => $checkOut->toTimeString(),
                            'expected_check_in' => $expectedCheckIn,
                            'expected_check_out' => $expectedCheckOut,
                            'working_hours' => $halfDayHours,
                            'overtime_hours' => 0,
                            'is_late' => false,
                            'minutes_early_departure' => max((int) round(($scheduledHours - $halfDayHours) * 60), 0),
                        ]
                    );
                }

                $date->addDay();
            }
        }

        private function resolveExpectedSchedule(ShopOwner $shopOwner, Carbon $date): ?array
        {
            $dayKey = strtolower($date->format('l'));

            if ($shopOwner->hasScheduleOn($dayKey) && ! $shopOwner->isOpenOn($dayKey)) {
                return null;
            }

            [$expectedCheckIn, $expectedCheckOut] = $this->resolveExpectedTimes($shopOwner, $dayKey);

            if ($expectedCheckIn === null || $expectedCheckOut === null) {
                if (! $date->isWeekday()) {
                    return null;
                }

                $expectedCheckIn = '08:00:00';
                $expectedCheckOut = '17:00:00';
            }

            return [
                'expected_check_in' => $expectedCheckIn,
                'expected_check_out' => $expectedCheckOut,
                'scheduled_hours' => $this->calculateScheduledHours($expectedCheckIn, $expectedCheckOut),
            ];
        }

        private function resolveExpectedTimes(ShopOwner $shopOwner, string $dayKey): array
        {
            $expectedCheckIn = $shopOwner->{$dayKey . '_open'} ?? null;
            $expectedCheckOut = $shopOwner->{$dayKey . '_close'} ?? null;

            if (! empty($expectedCheckIn) && ! empty($expectedCheckOut)) {
                return [
                    $this->normalizeSeedTime((string) $expectedCheckIn),
                    $this->normalizeSeedTime((string) $expectedCheckOut),
                ];
            }

            $operatingHours = $shopOwner->operating_hours;
            if (is_string($operatingHours)) {
                $decoded = json_decode($operatingHours, true);
                $operatingHours = is_array($decoded) ? $decoded : [];
            }

            $dayConfig = is_array($operatingHours) ? ($operatingHours[$dayKey] ?? null) : null;
            if (! is_array($dayConfig)) {
                return [null, null];
            }

            $isClosed = filter_var($dayConfig['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isClosed || empty($dayConfig['open']) || empty($dayConfig['close'])) {
                return [null, null];
            }

            return [
                $this->normalizeSeedTime((string) $dayConfig['open']),
                $this->normalizeSeedTime((string) $dayConfig['close']),
            ];
        }

        private function normalizeSeedTime(string $time): string
        {
            return strlen($time) === 5 ? $time . ':00' : $time;
        }

        private function calculateScheduledHours(string $expectedCheckIn, string $expectedCheckOut): float
        {
            $start = Carbon::parse('2000-01-01 ' . $expectedCheckIn);
            $end = Carbon::parse('2000-01-01 ' . $expectedCheckOut);

            if ($end->lte($start)) {
                $end->addDay();
            }

            return round($start->diffInMinutes($end) / 60, 2);
        }

        /**
         * Create leave requests for an employee
         */
        private function createLeaveRequests(Employee $employee, ShopOwner $shopOwner, User $approver): void
        {
            $leaveTemplates = [
                [
                    'leave_type' => 'sick',
                    'anchor_date' => Carbon::now()->subDays(45),
                    'workdays' => 1,
                    'reason' => 'Medical consultation',
                ],
                [
                    'leave_type' => 'vacation',
                    'anchor_date' => Carbon::now()->subDays(30),
                    'workdays' => 4,
                    'reason' => 'Vacation leave',
                ],
                [
                    'leave_type' => 'sick',
                    'anchor_date' => Carbon::now()->subDays(15),
                    'workdays' => 1,
                    'reason' => 'Dental appointment',
                ],
            ];

            $seedReasons = array_map(
                static fn (array $template): string => $template['reason'],
                $leaveTemplates
            );

            LeaveRequest::where('employee_id', $employee->id)
                ->where('shop_owner_id', $shopOwner->id)
                ->whereIn('reason', $seedReasons)
                ->delete();

            foreach ($leaveTemplates as $leaveData) {
                $range = $this->resolveConsecutiveScheduledLeaveRange(
                    $shopOwner,
                    $leaveData['anchor_date'],
                    (int) $leaveData['workdays']
                );

                if ($range === null) {
                    continue;
                }

                LeaveRequest::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'shop_owner_id' => $shopOwner->id,
                        'start_date' => $range['start_date']->toDateString(),
                        'end_date' => $range['end_date']->toDateString(),
                    ],
                    [
                        'leave_type' => $leaveData['leave_type'],
                        'no_of_days' => $range['no_of_days'],
                        'reason' => $leaveData['reason'],
                        'status' => 'approved',
                        'approved_by' => $approver->id,
                        'approval_date' => $range['start_date']->copy()->subDay(),
                    ]
                );
            }
        }

        private function resolveConsecutiveScheduledLeaveRange(ShopOwner $shopOwner, Carbon $anchorDate, int $workdays): ?array
        {
            $daysToConsume = max($workdays, 1);
            $searchLimit = 45;

            for ($offset = 0; $offset <= $searchLimit; $offset++) {
                $start = $anchorDate->copy()->addDays($offset);
                if (! $this->isScheduledWorkday($shopOwner, $start)) {
                    continue;
                }

                $cursor = $start->copy();
                $isValid = true;

                for ($i = 0; $i < $daysToConsume; $i++) {
                    if (! $this->isScheduledWorkday($shopOwner, $cursor)) {
                        $isValid = false;
                        break;
                    }

                    $cursor->addDay();
                }

                if ($isValid) {
                    return [
                        'start_date' => $start,
                        'end_date' => $start->copy()->addDays($daysToConsume - 1),
                        'no_of_days' => $daysToConsume,
                    ];
                }
            }

            return null;
        }

        private function isScheduledWorkday(ShopOwner $shopOwner, Carbon $date): bool
        {
            $dayKey = strtolower($date->format('l'));

            if ($shopOwner->hasScheduleOn($dayKey)) {
                return $shopOwner->isOpenOn($dayKey);
            }

            return $date->isWeekday();
        }

}
