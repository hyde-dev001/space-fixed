<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\OvertimeRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class EmployeeAttendanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates employees with complete attendance records for payroll slip testing
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting Employee Attendance Seeder...');

        // Get or create shop owner
        $shopOwner = ShopOwner::first();
        if (!$shopOwner) {
            $this->command->error('❌ No shop owner found. Please run ShopOwnerSeeder first.');
            return;
        }

        $this->command->info("✓ Using shop owner: {$shopOwner->name}");

        // Create test employees with different scenarios
        $employees = $this->createEmployees($shopOwner);
        
        // Generate attendance for the current month (January-February)
        $this->generateAttendanceRecords($employees, $shopOwner);
        
        // Generate overtime requests
        $this->generateOvertimeRecords($employees, $shopOwner);

        $this->command->info('✅ Employee Attendance Seeder completed successfully!');
    }

    /**
     * Create test employees with different roles and salary structures
     */
    private function createEmployees($shopOwner): array
    {
        $this->command->info('📝 Creating employees...');

        $employeesData = [
            [
                'name' => 'Carlos Rodriguez',
                'first_name' => 'Carlos',
                'last_name' => 'Rodriguez',
                'email' => 'carlos.rodriguez@solespace.com',
                'phone' => '09171234567',
                'position' => 'Store Manager',
                'department' => 'Management',
                'salary' => 45000.00,
                'sales_commission_rate' => 0.02, // 2%
                'performance_bonus_rate' => 0.10, // 10%
                'other_allowances' => 5000.00,
                'hire_date' => '2024-01-15',
                'status' => 'active',
                'address' => '123 Rizal Avenue, Makati City',
                'city' => 'Makati',
                'state' => 'Metro Manila',
                'zip_code' => '1200',
                'emergency_contact' => 'Maria Rodriguez',
                'emergency_phone' => '09181234567',
            ],
            [
                'name' => 'Isabella Fernandez',
                'first_name' => 'Isabella',
                'last_name' => 'Fernandez',
                'email' => 'isabella.fernandez@solespace.com',
                'phone' => '09171234568',
                'position' => 'Senior Sales Associate',
                'department' => 'Sales',
                'salary' => 25000.00,
                'sales_commission_rate' => 0.05, // 5%
                'performance_bonus_rate' => 0.08, // 8%
                'other_allowances' => 2000.00,
                'hire_date' => '2024-03-10',
                'status' => 'active',
                'address' => '456 Taft Avenue, Pasay City',
                'city' => 'Pasay',
                'state' => 'Metro Manila',
                'zip_code' => '1300',
                'emergency_contact' => 'Juan Fernandez',
                'emergency_phone' => '09181234568',
            ],
            [
                'name' => 'Miguel Santos',
                'first_name' => 'Miguel',
                'last_name' => 'Santos',
                'email' => 'miguel.santos@solespace.com',
                'phone' => '09171234569',
                'position' => 'Sales Associate',
                'department' => 'Sales',
                'salary' => 18000.00,
                'sales_commission_rate' => 0.03, // 3%
                'performance_bonus_rate' => 0.05, // 5%
                'other_allowances' => 1500.00,
                'hire_date' => '2024-06-01',
                'status' => 'active',
                'address' => '789 EDSA, Quezon City',
                'city' => 'Quezon City',
                'state' => 'Metro Manila',
                'zip_code' => '1100',
                'emergency_contact' => 'Ana Santos',
                'emergency_phone' => '09181234569',
            ],
            [
                'name' => 'Sofia Garcia',
                'first_name' => 'Sofia',
                'last_name' => 'Garcia',
                'email' => 'sofia.garcia@solespace.com',
                'phone' => '09171234570',
                'position' => 'Cashier',
                'department' => 'Operations',
                'salary' => 16000.00,
                'sales_commission_rate' => null,
                'performance_bonus_rate' => 0.05,
                'other_allowances' => 1000.00,
                'hire_date' => '2024-08-15',
                'status' => 'active',
                'address' => '321 Aurora Boulevard, Pasig City',
                'city' => 'Pasig',
                'state' => 'Metro Manila',
                'zip_code' => '1600',
                'emergency_contact' => 'Pedro Garcia',
                'emergency_phone' => '09181234570',
            ],
            [
                'name' => 'Luis Reyes',
                'first_name' => 'Luis',
                'last_name' => 'Reyes',
                'email' => 'luis.reyes@solespace.com',
                'phone' => '09171234571',
                'position' => 'Stock Clerk',
                'department' => 'Warehouse',
                'salary' => 15000.00,
                'sales_commission_rate' => null,
                'performance_bonus_rate' => null,
                'other_allowances' => 500.00,
                'hire_date' => '2025-01-05',
                'status' => 'active',
                'address' => '654 C5 Road, Taguig City',
                'city' => 'Taguig',
                'state' => 'Metro Manila',
                'zip_code' => '1630',
                'emergency_contact' => 'Carmen Reyes',
                'emergency_phone' => '09181234571',
            ],
        ];

        $employees = [];
        foreach ($employeesData as $empData) {
            $employee = Employee::updateOrCreate(
                ['email' => $empData['email']],
                array_merge($empData, [
                    'shop_owner_id' => $shopOwner->id,
                    'password' => Hash::make('password123'),
                ])
            );

            // Create corresponding user account
            User::updateOrCreate(
                ['email' => $empData['email']],
                [
                    'name' => $empData['name'],
                    'first_name' => $empData['first_name'],
                    'last_name' => $empData['last_name'],
                    'email' => $empData['email'],
                    'password' => Hash::make('password123'),
                    'shop_owner_id' => $shopOwner->id,
                    'role' => 'STAFF',
                ]
            );

            $employees[] = $employee;
            $this->command->info("  ✓ Created: {$empData['name']} - {$empData['position']}");
        }

        return $employees;
    }

    /**
     * Generate attendance records for the past month
     */
    private function generateAttendanceRecords($employees, $shopOwner): void
    {
        $this->command->info('📅 Generating attendance records...');

        // Generate for January 2026 (full month for testing)
        $startDate = Carbon::parse('2026-01-01');
        $endDate = Carbon::parse('2026-02-09'); // Up to current date
        
        $shopOpenTime = '09:00:00';
        $shopCloseTime = '17:00:00';

        foreach ($employees as $index => $employee) {
            $currentDate = $startDate->copy();
            $daysGenerated = 0;

            while ($currentDate->lte($endDate)) {
                // Skip Sundays (shop closed)
                if ($currentDate->dayOfWeek === Carbon::SUNDAY) {
                    $currentDate->addDay();
                    continue;
                }

                // Create different attendance patterns for variety
                $scenario = $this->getAttendanceScenario($index, $currentDate, $daysGenerated);

                // Create attendance record for all days (including absences)
                AttendanceRecord::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'date' => $currentDate->format('Y-m-d'),
                    ],
                    [
                        'shop_owner_id' => $shopOwner->id,
                        'check_in_time' => $scenario['check_in'],
                        'check_out_time' => $scenario['check_out'],
                        'expected_check_in' => $shopOpenTime,
                        'expected_check_out' => $shopCloseTime,
                        'working_hours' => $scenario['hours'],
                        'overtime_hours' => $scenario['overtime'],
                        'status' => $scenario['status'],
                        'is_late' => $scenario['is_late'],
                        'minutes_late' => $scenario['minutes_late'],
                        'is_early' => $scenario['is_early'],
                        'minutes_early' => $scenario['minutes_early'],
                        'is_early_departure' => $scenario['is_early_departure'],
                        'minutes_early_departure' => $scenario['minutes_early_departure'],
                        'lateness_reason' => $scenario['lateness_reason'],
                        'early_reason' => $scenario['early_reason'],
                    ]
                );
                $daysGenerated++;

                $currentDate->addDay();
            }

            $this->command->info("  ✓ {$employee->name}: {$daysGenerated} attendance records");
        }
    }

    /**
     * Determine attendance scenario based on employee and date
     */
    private function getAttendanceScenario($employeeIndex, $date, $dayCount): array
    {
        $shopOpenTime = '09:00:00';
        $shopCloseTime = '17:00:00';
        
        // Different patterns for each employee with realistic scenarios
        $scenarios = [
            // Employee 0: Mostly perfect, 1 absence, occasional early arrival
            0 => [
                'check_in' => $dayCount === 10 ? null : ($dayCount % 10 === 0 ? '08:50:00' : '09:00:00'),
                'check_out' => $dayCount === 10 ? null : '17:00:00',
                'hours' => $dayCount === 10 ? 0.00 : 8.00,
                'overtime' => 0.00,
                'status' => $dayCount === 10 ? 'absent' : 'present',
                'is_late' => false,
                'minutes_late' => 0,
                'is_early' => $dayCount % 10 === 0 && $dayCount !== 10,
                'minutes_early' => $dayCount % 10 === 0 && $dayCount !== 10 ? 10 : 0,
                'is_early_departure' => false,
                'minutes_early_departure' => 0,
                'lateness_reason' => null,
                'early_reason' => $dayCount % 10 === 0 && $dayCount !== 10 ? 'Opening duties preparation' : null,
            ],
            // Employee 1: Frequent late, 2 absences
            1 => [
                'check_in' => match(true) {
                    in_array($dayCount, [7, 20]) => null,  // Absent days
                    $dayCount % 3 === 0 => '09:25:00',      // Very late
                    $dayCount % 5 === 0 => '09:15:00',      // Moderately late
                    default => '09:05:00'                   // Slightly late
                },
                'check_out' => in_array($dayCount, [7, 20]) ? null : '17:00:00',
                'hours' => in_array($dayCount, [7, 20]) ? 0.00 : 8.00,
                'overtime' => 0.00,
                'status' => match(true) {
                    in_array($dayCount, [7, 20]) => 'absent',
                    $dayCount % 3 === 0 || $dayCount % 5 === 0 => 'late',
                    default => 'present'
                },
                'is_late' => $dayCount % 3 === 0 || $dayCount % 5 === 0,
                'minutes_late' => match(true) {
                    $dayCount % 3 === 0 => 25,
                    $dayCount % 5 === 0 => 15,
                    default => 0
                },
                'is_early' => false,
                'minutes_early' => 0,
                'is_early_departure' => false,
                'minutes_early_departure' => 0,
                'lateness_reason' => match(true) {
                    $dayCount % 3 === 0 => 'Heavy traffic on EDSA',
                    $dayCount % 5 === 0 => 'MRT breakdown',
                    default => null
                },
                'early_reason' => null,
            ],
            // Employee 2: Many absences and late days
            2 => [
                'check_in' => match(true) {
                    in_array($dayCount, [3, 8, 15, 22, 28]) => null,  // Absent days
                    $dayCount % 4 === 0 => '09:30:00',
                    default => '09:10:00'
                },
                'check_out' => match(true) {
                    in_array($dayCount, [3, 8, 15, 22, 28]) => null,  // Absent days
                    $dayCount % 6 === 0 => '16:30:00',  // Early departure
                    default => '17:00:00'
                },
                'hours' => match(true) {
                    in_array($dayCount, [3, 8, 15, 22, 28]) => 0.00,  // Absent
                    $dayCount % 6 === 0 => 7.50,  // Early departure
                    default => 8.00
                },
                'overtime' => 0.00,
                'status' => match(true) {
                    in_array($dayCount, [3, 8, 15, 22, 28]) => 'absent',
                    $dayCount % 6 === 0 => 'half_day',
                    $dayCount % 4 === 0 => 'late',
                    default => 'present'
                },
                'is_late' => $dayCount % 4 === 0,
                'minutes_late' => $dayCount % 4 === 0 ? 30 : 0,
                'is_early' => false,
                'minutes_early' => 0,
                'is_early_departure' => $dayCount % 6 === 0,
                'minutes_early_departure' => $dayCount % 6 === 0 ? 30 : 0,
                'lateness_reason' => $dayCount % 4 === 0 ? 'Personal matter' : null,
                'early_reason' => null,
            ],
            // Employee 3: Perfect attendance, very punctual
            3 => [
                'check_in' => '09:00:00',
                'check_out' => '17:00:00',
                'hours' => 8.00,
                'overtime' => 0.00,
                'status' => 'present',
                'is_late' => false,
                'minutes_late' => 0,
                'is_early' => false,
                'minutes_early' => 0,
                'is_early_departure' => false,
                'minutes_early_departure' => 0,
                'lateness_reason' => null,
                'early_reason' => null,
            ],
            // Employee 4: New employee, some absences and learning curve  
            4 => [
                'check_in' => match(true) {
                    !$date->gte(Carbon::parse('2026-01-06')) => null,  // Not hired yet
                    in_array($dayCount, [12, 25]) => null,  // Absent days
                    $dayCount % 4 === 0 => '09:20:00',
                    $dayCount % 7 === 0 => '09:12:00',
                    default => '09:03:00'
                },
                'check_out' => match(true) {
                    !$date->gte(Carbon::parse('2026-01-06')) => null,
                    in_array($dayCount, [12, 25]) => null,
                    default => '17:00:00'
                },
                'hours' => match(true) {
                    !$date->gte(Carbon::parse('2026-01-06')) => 0.00,
                    in_array($dayCount, [12, 25]) => 0.00,
                    default => 8.00
                },
                'overtime' => 0.00,
                'status' => match(true) {
                    !$date->gte(Carbon::parse('2026-01-06')) => 'absent',
                    in_array($dayCount, [12, 25]) => 'absent',
                    $dayCount % 4 === 0 || $dayCount % 7 === 0 => 'late',
                    default => 'present'
                },
                'is_late' => $dayCount % 4 === 0 || $dayCount % 7 === 0,
                'minutes_late' => match(true) {
                    $dayCount % 4 === 0 => 20,
                    $dayCount % 7 === 0 => 12,
                    default => 0
                },
                'is_early' => false,
                'minutes_early' => 0,
                'is_early_departure' => false,
                'minutes_early_departure' => 0,
                'lateness_reason' => match(true) {
                    $dayCount % 4 === 0 => 'Still learning the route',
                    $dayCount % 7 === 0 => 'Bus delay',
                    default => null
                },
                'early_reason' => null,
            ],
        ];

        return $scenarios[$employeeIndex] ?? $scenarios[0];
    }

    /**
     * Generate overtime requests
     */
    private function generateOvertimeRecords($employees, $shopOwner): void
    {
        $this->command->info('⏰ Generating overtime records...');

        // Generate overtime for select employees
        $overtimeData = [
            // Employee 0: Manager - multiple overtime sessions
            [
                'employee_index' => 0,
                'records' => [
                    ['date' => '2026-01-15', 'hours' => 3, 'reason' => 'Inventory count', 'status' => 'approved', 'completed' => true, 'actual_hours' => 3.5],
                    ['date' => '2026-01-22', 'hours' => 2, 'reason' => 'Month-end reports', 'status' => 'approved', 'completed' => true, 'actual_hours' => 2.0],
                    ['date' => '2026-02-05', 'hours' => 4, 'reason' => 'Store renovation supervision', 'status' => 'approved', 'completed' => true, 'actual_hours' => 4.5],
                ],
            ],
            // Employee 1: Senior Sales - occasional overtime
            [
                'employee_index' => 1,
                'records' => [
                    ['date' => '2026-01-18', 'hours' => 2, 'reason' => 'Big sale event', 'status' => 'approved', 'completed' => true, 'actual_hours' => 2.5],
                    ['date' => '2026-02-01', 'hours' => 3, 'reason' => 'New product display setup', 'status' => 'approved', 'completed' => true, 'actual_hours' => 3.0],
                ],
            ],
            // Employee 2: Some overtime
            [
                'employee_index' => 2,
                'records' => [
                    ['date' => '2026-01-25', 'hours' => 2, 'reason' => 'Customer service training', 'status' => 'approved', 'completed' => true, 'actual_hours' => 2.0],
                ],
            ],
        ];

        foreach ($overtimeData as $data) {
            $employee = $employees[$data['employee_index']];
            
            foreach ($data['records'] as $record) {
                $startTime = Carbon::parse($record['date'] . ' 17:00:00');
                $endTime = $startTime->copy()->addHours($record['hours']);
                
                $overtime = OvertimeRequest::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'overtime_date' => $record['date'],
                    ],
                    [
                        'shop_owner_id' => $shopOwner->id,
                        'start_time' => '17:00:00',
                        'end_time' => $endTime->format('H:i:s'),
                        'hours' => $record['hours'],
                        'rate_multiplier' => 1.50,
                        'overtime_type' => 'weekday',
                        'reason' => $record['reason'],
                        'status' => $record['status'],
                        'approved_at' => Carbon::parse($record['date'])->subDays(1),
                        'approved_by' => 1, // Assuming admin user ID 1
                    ]
                );

                // If completed, add check-in/out times
                if ($record['completed']) {
                    $actualEndTime = Carbon::parse($record['date'] . ' 17:00:00')->addHours($record['actual_hours']);
                    $overtime->update([
                        'checked_in_at' => Carbon::parse($record['date'] . ' 17:00:00'),
                        'actual_start_time' => '17:00:00',
                        'checked_out_at' => $actualEndTime,
                        'actual_end_time' => $actualEndTime->format('H:i:s'),
                        'actual_hours' => $record['actual_hours'],
                        'calculated_amount' => ($employee->salary / 22 / 8) * $record['actual_hours'] * 1.50,
                    ]);

                    // Update attendance record with overtime hours
                    AttendanceRecord::where('employee_id', $employee->id)
                        ->where('date', $record['date'])
                        ->update(['overtime_hours' => $record['actual_hours']]);
                }
            }

            $overtimeCount = count($data['records']);
            $this->command->info("  ✓ {$employee->name}: {$overtimeCount} overtime records");
        }
    }
}
