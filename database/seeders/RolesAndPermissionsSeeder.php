<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Creating department-based roles with permissions...');

        // ===== ALL PERMISSIONS =====
        
        $allPermissions = [
            // Finance
            'view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses', 'approve-expenses',
            'view-invoices', 'create-invoices', 'edit-invoices', 'delete-invoices', 'send-invoices',
            'view-finance-reports', 'export-finance-reports', 'view-finance-audit-logs',
            'manage-cost-centers', 'view-revenue-accounts', 'reconcile-accounts',
            
            // HR
            'view-employees', 'create-employees', 'edit-employees', 'delete-employees', 'approve-employee-changes',
            'view-attendance', 'create-attendance', 'edit-attendance', 'approve-timeoff',
            'view-payroll', 'process-payroll', 'approve-payroll', 'generate-payslip',
            'view-hr-reports', 'export-hr-reports', 'view-hr-audit-logs',
            
            // CRM
            'view-customers', 'create-customers', 'edit-customers', 'delete-customers',
            'view-leads', 'create-leads', 'edit-leads', 'convert-leads', 'assign-leads',
            'view-opportunities', 'create-opportunities', 'edit-opportunities', 'close-opportunities',
            'view-crm-reports', 'export-crm-reports', 'view-crm-audit-logs',
            
            // Management
            'view-all-users', 'create-users', 'edit-users', 'delete-users', 'assign-roles',
            'view-products', 'create-products', 'edit-products', 'delete-products', 'manage-inventory',
            'view-pricing', 'edit-pricing', 'manage-service-pricing',
            'view-all-audit-logs', 'view-system-reports', 'manage-shop-settings',
            
            // Job Orders
            'view-job-orders', 'create-job-orders', 'edit-job-orders', 'complete-job-orders',
            
            // General
            'view-dashboard',
        ];

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'user']
            );
        }

        $this->command->info('Created ' . count($allPermissions) . ' permissions.');

        // ===== CREATE SIMPLIFIED DEPARTMENT ROLES =====

        $this->command->info('Creating simplified department roles...');

        // 1. Manager Role - Full Access
        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'user']);
        $manager->syncPermissions(Permission::where('guard_name', 'user')->pluck('name'));
        $this->command->info('✓ Manager role: ALL ' . $manager->permissions->count() . ' permissions');

        // 2. Finance Role - Full Finance Module Access
        $finance = Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'user']);
        $finance->syncPermissions([
            // Expenses
            'view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses', 'approve-expenses',
            // Invoices
            'view-invoices', 'create-invoices', 'edit-invoices', 'delete-invoices', 'send-invoices',
            // Payroll Approvals (Finance must approve before HR releases)
            'view-payroll', 'approve-payroll',
            // Reports & Audit
            'view-finance-reports', 'export-finance-reports', 'view-finance-audit-logs',
            // Advanced Finance
            'manage-cost-centers', 'view-revenue-accounts', 'reconcile-accounts',
            // Pricing Management (Repair & Shoe Pricing)
            'view-pricing', 'edit-pricing', 'manage-service-pricing',
            // General
            'view-dashboard',
        ]);
        $this->command->info('✓ Finance role: ' . $finance->permissions->count() . ' permissions (Full Finance module + Payroll Approval)');

        // 3. HR Role - Full HR Module Access
        $hr = Role::firstOrCreate(['name' => 'HR', 'guard_name' => 'user']);
        $hr->syncPermissions([
            // Employee Management
            'view-employees', 'create-employees', 'edit-employees', 'delete-employees', 'approve-employee-changes',
            // Attendance & Leave
            'view-attendance', 'create-attendance', 'edit-attendance', 'approve-timeoff',
            // Payroll
            'view-payroll', 'process-payroll', 'approve-payroll', 'generate-payslip',
            // Reports & Audit
            'view-hr-reports', 'export-hr-reports', 'view-hr-audit-logs',
            // General
            'view-dashboard',
        ]);
        $this->command->info('✓ HR role: ' . $hr->permissions->count() . ' permissions (Full HR module)');

        // 4. CRM Role - Full CRM Module Access
        $crm = Role::firstOrCreate(['name' => 'CRM', 'guard_name' => 'user']);
        $crm->syncPermissions([
            // Customers
            'view-customers', 'create-customers', 'edit-customers', 'delete-customers',
            // Leads
            'view-leads', 'create-leads', 'edit-leads', 'convert-leads', 'assign-leads',
            // Opportunities
            'view-opportunities', 'create-opportunities', 'edit-opportunities', 'close-opportunities',
            // Reports & Audit
            'view-crm-reports', 'export-crm-reports', 'view-crm-audit-logs',
            // General
            'view-dashboard',
        ]);
        $this->command->info('✓ CRM role: ' . $crm->permissions->count() . ' permissions (Full CRM module)');

        // 5. Staff Role - Basic Access (configurable)
        $staff = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'user']);
        $staff->syncPermissions([
            'view-dashboard',
            'view-job-orders',
            'create-job-orders',
        ]);
        $this->command->info('✓ Staff role: ' . $staff->permissions->count() . ' base permissions (HR can add more)');

        // ===== SHOP OWNER GUARD =====
        
        $this->command->info('Creating Shop Owner role...');
        
        $shopOwnerRole = Role::firstOrCreate(['name' => 'Shop Owner', 'guard_name' => 'shop_owner']);
        $this->command->info('✓ Shop Owner role created (full access)');

        // ===== SUPER ADMIN GUARD =====
        
        $this->command->info('Creating Super Admin role...');
        
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'super_admin']);
        $this->command->info('✓ Super Admin role created (full system access)');

        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('✅ SIMPLIFIED DEPARTMENT ROLES CREATED!');
        $this->command->info('========================================');
        $this->command->info('Total Roles: ' . Role::where('guard_name', 'user')->count());
        $this->command->info('Total Permissions: ' . Permission::count());
        $this->command->info('');
        $this->command->info('Available Roles:');
        $this->command->info('  1. Manager (' . $manager->permissions->count() . ' perms) - Full system access');
        $this->command->info('  2. Finance (' . $finance->permissions->count() . ' perms) - Full Finance module');
        $this->command->info('  3. HR (' . $hr->permissions->count() . ' perms) - Full HR module');
        $this->command->info('  4. CRM (' . $crm->permissions->count() . ' perms) - Full CRM module');
        $this->command->info('  5. Staff (' . $staff->permissions->count() . ' perms) - Basic + HR can add more');
        $this->command->info('');
        $this->command->info('💡 HR/Shop Owner can grant additional permissions on top of role!');
        $this->command->info('========================================');
    }
}
