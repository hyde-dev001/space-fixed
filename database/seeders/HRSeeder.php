<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Employee;
use App\Models\HR\Department;
use App\Models\HR\LeaveBalance;
use App\Models\HR\AttendanceRecord;
use Carbon\Carbon;

class HRSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first shop owner for seeding
        $shopOwner = \App\Models\ShopOwner::first();
        
        if (!$shopOwner) {
            $this->command->warn('No shop owner found. Skipping HR seeding.');
            return;
        }

        $this->command->info('Seeding HR data for shop owner: ' . $shopOwner->name);

        // Create departments
        $departments = [
            [
                'name' => 'Sales',
                'description' => 'Sales and customer service department',
                'manager_name' => 'Maria Santos',
                'location' => 'Ground Floor',
            ],
            [
                'name' => 'Operations',
                'description' => 'Store operations and inventory management',
                'manager_name' => 'Juan Dela Cruz',
                'location' => 'Back Office',
            ],
            [
                'name' => 'Administration',
                'description' => 'HR, Finance, and administrative functions',
                'manager_name' => 'Anna Garcia',
                'location' => 'Second Floor',
            ],
        ];

        foreach ($departments as $deptData) {
            Department::create(array_merge($deptData, [
                'shop_owner_id' => $shopOwner->id,
                'is_active' => true,
            ]));
        }

        // Create sample employees if none exist
        if (Employee::where('shop_owner_id', $shopOwner->id)->count() === 0) {
            $employees = [
                [
                    'name' => 'Juan Dela Cruz',
                    'first_name' => 'Juan',
                    'last_name' => 'Dela Cruz',
                    'email' => 'juan.delacruz@solespace.com',
                    'phone' => '09171234567',
                    'position' => 'Store Manager',
                    'department' => 'Operations',
                    'salary' => 35000,
                    'hire_date' => '2023-01-15',
                    'status' => 'active',
                    'address' => '123 Main St, Quezon City',
                    'city' => 'Quezon City',
                    'state' => 'Metro Manila',
                    'zip_code' => '1100',
                    'emergency_contact' => 'Maria Dela Cruz',
                    'emergency_phone' => '09181234567',
                ],
                [
                    'name' => 'Maria Santos',
                    'first_name' => 'Maria',
                    'last_name' => 'Santos',
                    'email' => 'maria.santos@solespace.com',
                    'phone' => '09171234568',
                    'position' => 'Sales Associate',
                    'department' => 'Sales',
                    'salary' => 18000,
                    'hire_date' => '2023-03-10',
                    'status' => 'active',
                    'address' => '456 Rizal Ave, Makati City',
                    'city' => 'Makati',
                    'state' => 'Metro Manila',
                    'zip_code' => '1200',
                    'emergency_contact' => 'Jose Santos',
                    'emergency_phone' => '09181234568',
                ],
                [
                    'name' => 'Anna Garcia',
                    'first_name' => 'Anna',
                    'last_name' => 'Garcia',
                    'email' => 'anna.garcia@solespace.com',
                    'phone' => '09171234569',
                    'position' => 'HR Assistant',
                    'department' => 'Administration',
                    'salary' => 25000,
                    'hire_date' => '2023-06-01',
                    'status' => 'active',
                    'address' => '789 EDSA, Pasig City',
                    'city' => 'Pasig',
                    'state' => 'Metro Manila',
                    'zip_code' => '1600',
                    'emergency_contact' => 'Carlos Garcia',
                    'emergency_phone' => '09181234569',
                ],
            ];

            foreach ($employees as $empData) {
                $employee = Employee::create(array_merge($empData, [
                    'shop_owner_id' => $shopOwner->id,
                    'password' => bcrypt('password'),
                ]));

                // Create leave balance for each employee
                LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'shop_owner_id' => $shopOwner->id,
                    'year' => 2026,
                    'vacation_days' => 15,
                    'sick_days' => 10,
                    'personal_days' => 5,
                    'maternity_days' => 60,
                    'paternity_days' => 7,
                    'used_vacation' => rand(0, 3),
                    'used_sick' => rand(0, 2),
                    'used_personal' => rand(0, 1),
                ]);

                // Create some attendance records for the past week
                $startDate = Carbon::now()->subDays(7);
                for ($i = 0; $i < 7; $i++) {
                    $date = $startDate->copy()->addDays($i);
                    
                    // Skip weekends
                    if ($date->isWeekend()) {
                        continue;
                    }

                    $status = ['present', 'present', 'present', 'present', 'late'][rand(0, 4)];
                    $checkIn = $date->copy()->setTime(8, rand(0, 30), 0);
                    $checkOut = $checkIn->copy()->addHours(8)->addMinutes(rand(0, 30));

                    AttendanceRecord::create([
                        'employee_id' => $employee->id,
                        'shop_owner_id' => $shopOwner->id,
                        'date' => $date->format('Y-m-d'),
                        'check_in_time' => $checkIn->format('H:i:s'),
                        'check_out_time' => $checkOut->format('H:i:s'),
                        'status' => $status,
                        'working_hours' => 8,
                    ]);
                }
            }

            $this->command->info('Created ' . count($employees) . ' sample employees with attendance records');
        }

        $this->command->info('HR seeding completed successfully!');
    }
}