<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ERPUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating ERP test accounts for all roles...');
        
        // Default password for all test accounts
        $password = Hash::make('password123');
        
        // All users belong to shop_owner_id = 1
        $shopOwnerId = 1;

        // ===== 1. MANAGER =====
        $manager = User::firstOrCreate(
            ['email' => 'manager@solespace.test'],
            [
                'first_name' => 'John',
                'last_name' => 'Manager',
                'name' => 'John Manager',
                'phone' => '09123456701',
                'age' => 35,
                'address' => 'Quezon City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'Manager',
                'email_verified_at' => now(),
            ]
        );
        $managerRole = Role::where('name', 'Manager')->where('guard_name', 'user')->first();
        if ($managerRole) {
            $manager->assignRole($managerRole);
        }
        $this->command->info('✓ Manager account created: manager@solespace.test');

        // ===== 2. FINANCE =====
        $finance = User::firstOrCreate(
            ['email' => 'finance@solespace.test'],
            [
                'first_name' => 'Maria',
                'last_name' => 'Finance',
                'name' => 'Maria Finance',
                'phone' => '09123456702',
                'age' => 32,
                'address' => 'Makati City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'Finance',
                'email_verified_at' => now(),
            ]
        );
        $financeRole = Role::where('name', 'Finance')->where('guard_name', 'user')->first();
        if ($financeRole) {
            $finance->assignRole($financeRole);
        }
        $this->command->info('✓ Finance account created: finance@solespace.test');

        // ===== 3. HR =====
        $hr = User::firstOrCreate(
            ['email' => 'hr@solespace.test'],
            [
                'first_name' => 'Patricia',
                'last_name' => 'Human Resources',
                'name' => 'Patricia Human Resources',
                'phone' => '09123456703',
                'age' => 30,
                'address' => 'Taguig City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'HR',
                'email_verified_at' => now(),
            ]
        );
        $hrRole = Role::where('name', 'HR')->where('guard_name', 'user')->first();
        if ($hrRole) {
            $hr->assignRole($hrRole);
        }
        $this->command->info('✓ HR account created: hr@solespace.test');

        // ===== 4. CRM =====
        $crm = User::firstOrCreate(
            ['email' => 'crm@solespace.test'],
            [
                'first_name' => 'Carlos',
                'last_name' => 'Customer Relations',
                'name' => 'Carlos Customer Relations',
                'phone' => '09123456704',
                'age' => 28,
                'address' => 'Pasig City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'CRM',
                'email_verified_at' => now(),
            ]
        );
        $crmRole = Role::where('name', 'CRM')->where('guard_name', 'user')->first();
        if ($crmRole) {
            $crm->assignRole($crmRole);
        }
        $this->command->info('✓ CRM account created: crm@solespace.test');

        // ===== 5. REPAIRER =====
        $repairer = User::firstOrCreate(
            ['email' => 'repairer@solespace.test'],
            [
                'first_name' => 'Roberto',
                'last_name' => 'Repair Technician',
                'name' => 'Roberto Repair Technician',
                'phone' => '09123456705',
                'age' => 27,
                'address' => 'Manila City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'Repairer',
                'email_verified_at' => now(),
            ]
        );
        $repairerRole = Role::where('name', 'Repairer')->where('guard_name', 'user')->first();
        if ($repairerRole) {
            $repairer->assignRole($repairerRole);
        }
        $this->command->info('✓ Repairer account created: repairer@solespace.test');

        // ===== 6. INVENTORY MANAGER =====
        $inventoryManager = User::firstOrCreate(
            ['email' => 'inventory@solespace.test'],
            [
                'first_name' => 'Isabel',
                'last_name' => 'Inventory',
                'name' => 'Isabel Inventory',
                'phone' => '09123456706',
                'age' => 29,
                'address' => 'Parañaque City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'INVENTORY',
                'email_verified_at' => now(),
            ]
        );
        $inventoryRole = Role::where('name', 'Inventory Manager')->where('guard_name', 'user')->first();
        if ($inventoryRole) {
            $inventoryManager->assignRole($inventoryRole);
        }
        $this->command->info('✓ Inventory Manager account created: inventory@solespace.test');

        // ===== 7. STAFF =====
        $staff = User::firstOrCreate(
            ['email' => 'staff@solespace.test'],
            [
                'first_name' => 'Samuel',
                'last_name' => 'Staff Member',
                'name' => 'Samuel Staff Member',
                'phone' => '09123456707',
                'age' => 24,
                'address' => 'Las Piñas City, Metro Manila',
                'password' => $password,
                'status' => 'active',
                'shop_owner_id' => $shopOwnerId,
                'role' => 'Staff',
                'email_verified_at' => now(),
            ]
        );
        $staffRole = Role::where('name', 'Staff')->where('guard_name', 'user')->first();
        if ($staffRole) {
            $staff->assignRole($staffRole);
        }
        $this->command->info('✓ Staff account created: staff@solespace.test');

        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('✅ ERP TEST ACCOUNTS CREATED!');
        $this->command->info('========================================');
        $this->command->info('Default password for all accounts: password123');
        $this->command->info('');
        $this->command->info('Login Credentials:');
        $this->command->info('  1. Manager:            manager@solespace.test');
        $this->command->info('  2. Finance:            finance@solespace.test');
        $this->command->info('  3. HR:                 hr@solespace.test');
        $this->command->info('  4. CRM:                crm@solespace.test');
        $this->command->info('  5. Repairer:           repairer@solespace.test');
        $this->command->info('  6. Inventory Manager:  inventory@solespace.test');
        $this->command->info('  7. Staff:              staff@solespace.test');
        $this->command->info('========================================');
    }
}
