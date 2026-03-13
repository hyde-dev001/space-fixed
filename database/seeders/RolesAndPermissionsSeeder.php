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

        $this->command->info('Creating simplified page-based permissions...');

        // ===== SIMPLIFIED PAGE-LEVEL PERMISSIONS =====
        // Each permission grants full access to a specific page/module
        // No more scattered CRUD permissions
        
        $allPermissions = [
            // ===== FINANCE MODULE =====
            'access-finance-dashboard',
            'access-finance-expenses',
            'access-finance-invoices',
            'access-approval-workflow',
            'access-payslip-approval',
            'access-refund-approval',
            'access-repair-price-approval',
            'access-shoe-price-approval',
            
            // ===== HR MODULE =====
            'access-hr-dashboard',
            'access-employee-directory',
            'access-attendance-records',
            'access-leave-approvals',
            'access-overtime-approvals',
            'access-payslip-generation',
            'access-view-payslip',
            
            // ===== CRM MODULE =====
            'access-crm-dashboard',
            'access-crm-customers',
            'access-customer-support',
            'access-customer-reviews',
            'access-crm-messages',
            
            // ===== MANAGER MODULE =====
            'access-manager-dashboard',
            'access-audit-logs',
            'access-manager-reports',
            'access-inventory-overview',
            'access-product-upload-manager',
            'access-repair-reject-review',
            'access-suspend-account',
            
            // ===== REPAIRER MODULE =====
            'access-repairer-dashboard',
            'access-repair-job-orders',
            'access-pricing-services',
            'access-repairer-support',
            'access-repair-stocks',
            'access-upload-service',
            
            // ===== INVENTORY MODULE =====
            'access-inventory-dashboard',
            'access-product-inventory',
            'access-stock-movement',
            'access-upload-inventory',
            'view-inventory', // Required for erp/inventory route group

            // ===== PROCUREMENT MODULE =====
            'access-procurement-dashboard',
            'access-purchase-requests',
            'access-purchase-orders',
            'access-stock-request-approval',
            'access-suppliers-management',
            'access-supplier-order-monitoring',
            'view-procurement', // Required for erp/procurement route group
            
            // ===== STAFF MODULE =====
            'access-staff-dashboard',
            'access-staff-job-orders',
            'access-product-management',
            'access-product-upload-staff',
            'access-shoe-pricing',
            'access-staff-time-in',
            'access-staff-leave',
            'access-color-variant-manager',
            'access-staff-customers',
            
            // ===== COMMON/GLOBAL =====
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ];

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'user']
            );
        }

        $this->command->info('Created ' . count($allPermissions) . ' simplified page-based permissions.');

        // ===== CREATE ROLE ASSIGNMENTS =====

        $this->command->info('Assigning simplified permissions to roles...');

        // 1. Manager Role - Full System Access & User Management
        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'user']);
        $manager->syncPermissions([
            // Manager Pages
            'access-manager-dashboard',
            'access-audit-logs',
            'access-manager-reports',
            'access-inventory-overview',
            'access-product-upload-manager',
            'access-repair-reject-review',
            'access-suspend-account',
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ Manager role: ' . $manager->permissions->count() . ' permissions (Manager pages + System oversight)');

        // 2. Finance Role - Full Finance Module Access
        $finance = Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'user']);
        $finance->syncPermissions([
            // Finance Pages
            'access-finance-dashboard',
            'access-finance-expenses',
            'access-finance-invoices',
            'access-approval-workflow',
            'access-payslip-approval',
            'access-refund-approval',
            'access-repair-price-approval',
            'access-shoe-price-approval',
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ Finance role: ' . $finance->permissions->count() . ' permissions (Full Finance module)');

        // 3. HR Role - Full HR Module Access
        $hr = Role::firstOrCreate(['name' => 'HR', 'guard_name' => 'user']);
        $hr->syncPermissions([
            // HR Pages
            'access-hr-dashboard',
            'access-employee-directory',
            'access-attendance-records',
            'access-leave-approvals',
            'access-overtime-approvals',
            'access-payslip-generation',
            'access-view-payslip',
            'access-audit-logs',
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ HR role: ' . $hr->permissions->count() . ' permissions (Full HR module)');

        // 4. CRM Role - Full CRM Module Access
        $crm = Role::firstOrCreate(['name' => 'CRM', 'guard_name' => 'user']);
        $crm->syncPermissions([
            // CRM Pages
            'access-crm-dashboard',
            'access-crm-customers',
            'access-customer-support',
            'access-customer-reviews',
            'access-crm-messages',
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ CRM role: ' . $crm->permissions->count() . ' permissions (Full CRM module)');

        // 5. Repairer Role - Full Repairer Module Access
        $repairer = Role::firstOrCreate(['name' => 'Repairer', 'guard_name' => 'user']);
        $repairer->syncPermissions([
            // Repairer Pages
            'access-repairer-dashboard',
            'access-repair-job-orders',
            'access-pricing-services',
            'access-repairer-support',
            'access-repair-stocks',
            'access-upload-service',
            // Time & Attendance
            'access-staff-time-in',
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ Repairer role: ' . $repairer->permissions->count() . ' permissions (Full Repairer module)');

        // 6. Inventory Manager Role - Inventory Module Only
        $inventoryManager = Role::firstOrCreate(['name' => 'Inventory Manager', 'guard_name' => 'user']);
        $inventoryManager->syncPermissions([
            // Inventory Pages
            'access-inventory-dashboard',
            'access-product-inventory',
            'access-stock-movement',
            'access-upload-inventory',
            'access-inventory-overview',
            'view-inventory', // Required for erp/inventory route group
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ Inventory Manager role: ' . $inventoryManager->permissions->count() . ' permissions (Inventory module only)');

        // 6b. Procurement Manager Role - Procurement Module Only
        $procurementManager = Role::firstOrCreate(['name' => 'Procurement Manager', 'guard_name' => 'user']);
        $procurementManager->syncPermissions([
            // Procurement Pages
            'access-procurement-dashboard',
            'access-purchase-requests',
            'access-purchase-orders',
            'access-stock-request-approval',
            'access-suppliers-management',
            'access-supplier-order-monitoring',
            'view-procurement', // Required for erp/procurement route group
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ Procurement Manager role: ' . $procurementManager->permissions->count() . ' permissions (Procurement module only)');

        // 7. Staff Role - Staff Module Access
        $staff = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'user']);
        $staff->syncPermissions([
            // Staff Pages
            'access-staff-dashboard',
            'access-staff-job-orders',
            'access-product-management',
            'access-product-upload-staff',
            'access-shoe-pricing',
            'access-staff-time-in',
            'access-staff-leave',
            'access-color-variant-manager',
            'access-staff-customers',
            // Global Access
            'access-global-search',
            'access-notification-center',
            'access-profile',
        ]);
        $this->command->info('✓ Staff role: ' . $staff->permissions->count() . ' permissions (Staff module + Basic access)');

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
        $this->command->info('✅ SIMPLIFIED PAGE-BASED ROLES CREATED!');
        $this->command->info('========================================');
        $this->command->info('Total Roles: ' . Role::where('guard_name', 'user')->count());
        $this->command->info('Total Permissions: ' . Permission::count());
        $this->command->info('');
        $this->command->info('Available Roles:');
        $this->command->info('  1. Manager (' . $manager->permissions->count() . ' perms) - Manager pages + System oversight');
        $this->command->info('  2. Finance (' . $finance->permissions->count() . ' perms) - Full Finance module');
        $this->command->info('  3. HR (' . $hr->permissions->count() . ' perms) - Full HR module');
        $this->command->info('  4. CRM (' . $crm->permissions->count() . ' perms) - Full CRM module');
        $this->command->info('  5. Repairer (' . $repairer->permissions->count() . ' perms) - Full Repairer module');
        $this->command->info('  6. Inventory Manager (' . $inventoryManager->permissions->count() . ' perms) - Inventory module only');
        $this->command->info('  7. Procurement Manager (' . $procurementManager->permissions->count() . ' perms) - Procurement module only');
        $this->command->info('  8. Staff (' . $staff->permissions->count() . ' perms) - Staff module + Basic access');
        $this->command->info('');
        $this->command->info('💡 HR/Shop Owner can grant additional permissions on top of role!');
        $this->command->info('========================================');
    }
}