<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class EmployeeSeeder extends Seeder
{
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
        
        // Common employees for all business types
        $commonEmployees = [
            // Manager - Full access
            [
                'first_name' => 'Michael',
                'last_name' => 'Anderson',
                'email' => "manager.{$shopOwner->id}@solespace.com",
                'position' => 'Store Manager',
                'department' => 'Manager',
                'salary' => 65000.00,
                'phone' => '+639171110001',
            ],
            // Finance Staff
            [
                'first_name' => 'Sarah',
                'last_name' => 'Johnson',
                'email' => "finance.{$shopOwner->id}@solespace.com",
                'position' => 'Finance Officer',
                'department' => 'Finance',
                'salary' => 45000.00,
                'phone' => '+639172220001',
            ],
            // HR Staff
            [
                'first_name' => 'David',
                'last_name' => 'Williams',
                'email' => "hr.{$shopOwner->id}@solespace.com",
                'position' => 'HR Specialist',
                'department' => 'HR',
                'salary' => 42000.00,
                'phone' => '+639173330001',
            ],
            // CRM Staff
            [
                'first_name' => 'Emily',
                'last_name' => 'Davis',
                'email' => "crm.{$shopOwner->id}@solespace.com",
                'position' => 'Customer Relations Officer',
                'department' => 'CRM',
                'salary' => 38000.00,
                'phone' => '+639174440001',
            ],
            // Inventory Manager
            [
                'first_name' => 'Robert',
                'last_name' => 'Martinez',
                'email' => "inventory.{$shopOwner->id}@solespace.com",
                'position' => 'Inventory Manager',
                'department' => 'Inventory Manager',
                'salary' => 48000.00,
                'phone' => '+639175550001',
            ],
            // Procurement Manager
            [
                'first_name' => 'Patricia',
                'last_name' => 'Reyes',
                'email' => "procurement.{$shopOwner->id}@solespace.com",
                'position' => 'Procurement Manager',
                'department' => 'Procurement Manager',
                'salary' => 50000.00,
                'phone' => '+639178880001',
            ],
            // General Staff
            [
                'first_name' => 'Jessica',
                'last_name' => 'Garcia',
                'email' => "staff.{$shopOwner->id}@solespace.com",
                'position' => 'Sales Associate',
                'department' => 'Staff',
                'salary' => 28000.00,
                'phone' => '+639176660001',
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
                'salary' => 35000.00,
                'phone' => '+639177770001',
            ];
        }

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
                    'password' => Hash::make('password123'),
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
                'Inventory Manager' => ['role' => 'INVENTORY_MANAGER', 'spatie' => 'Inventory Manager'],
                'Procurement Manager' => ['role' => 'PROCUREMENT_MANAGER', 'spatie' => 'Procurement Manager'],
                'Staff' => ['role' => 'STAFF', 'spatie' => 'Staff'],
            ];
            
            $department = $employeeData['department'];
            $mappedRole = $roleMap[$department] ?? ['role' => 'STAFF', 'spatie' => 'Staff'];
            
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
                    'password' => Hash::make('password123'),
                    'shop_owner_id' => $shopOwner->id, // Link user to shop owner
                    'role' => $mappedRole['role'], // Set role column (MANAGER, FINANCE, etc.)
                    'position' => $employeeData['position'],
                    'status' => 'active',
                    'force_password_change' => false, // Since we're using a known password
                ]
            );

            // Assign Spatie role based on department (use assignRole, not syncRoles)
            $user->roles()->detach();
            $user->assignRole($mappedRole['spatie']);

            $this->command->info("Created employee: {$employee->name} ({$mappedRole['role']}) for {$shopOwner->business_name}");
        }
    }
}
