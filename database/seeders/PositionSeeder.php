<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PositionTemplate;
use App\Models\PositionTemplatePermission;

/**
 * Position Seeder
 * 
 * Creates basic position templates with permission sets for the organization
 */
class PositionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding positions with permissions...');

        $positions = [
            // Finance Department
            [
                'name' => 'Finance Manager',
                'slug' => 'finance-manager',
                'description' => 'Oversees all financial operations and reports',
                'category' => 'Finance',
                'recommended_role' => 'Manager',
                'is_active' => true,
                'permissions' => [
                    'view-expenses',
                    'create-expenses',
                    'edit-expenses',
                    'delete-expenses',
                    'view-invoices',
                    'create-invoices',
                    'edit-invoices',
                    'delete-invoices',
                    'send-invoices',
                    'view-finance-reports',
                    'export-finance-reports',
                    'view-finance-audit-logs',
                    'view-dashboard',
                    'manage-cost-centers',
                    'view-revenue-accounts',
                ],
            ],
            [
                'name' => 'Accountant',
                'slug' => 'accountant',
                'description' => 'Manages financial records and reconciliation',
                'category' => 'Finance',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-expenses',
                    'create-expenses',
                    'edit-expenses',
                    'delete-expenses',
                    'view-invoices',
                    'create-invoices',
                    'edit-invoices',
                    'delete-invoices',
                    'send-invoices',
                    'view-finance-reports',
                    'export-finance-reports',
                    'view-finance-audit-logs',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'Bookkeeper',
                'slug' => 'bookkeeper',
                'description' => 'Records daily financial transactions',
                'category' => 'Finance',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-expenses',
                    'create-expenses',
                    'edit-expenses',
                    'view-invoices',
                    'create-invoices',
                    'edit-invoices',
                    'send-invoices',
                    'view-finance-reports',
                    'view-finance-audit-logs',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'Cashier',
                'slug' => 'cashier',
                'description' => 'Handles payments and receipts',
                'category' => 'Finance',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-invoices',
                    'create-invoices',
                    'send-invoices',
                    'view-customers',
                    'view-products',
                    'view-dashboard',
                ],
            ],

            // HR Department
            [
                'name' => 'HR Manager',
                'slug' => 'hr-manager',
                'description' => 'Manages all HR operations and employee relations',
                'category' => 'HR',
                'recommended_role' => 'Manager',
                'is_active' => true,
                'permissions' => [
                    'view-employees',
                    'create-employees',
                    'edit-employees',
                    'delete-employees',
                    'view-attendance',
                    'create-attendance',
                    'edit-attendance',
                    'approve-timeoff',
                    'view-payroll',
                    'process-payroll',
                    'generate-payslip',
                    'view-hr-reports',
                    'export-hr-reports',
                    'view-hr-audit-logs',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'HR Assistant',
                'slug' => 'hr-assistant',
                'description' => 'Provides support with employee administration',
                'category' => 'HR',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-employees',
                    'create-employees',
                    'edit-employees',
                    'view-attendance',
                    'create-attendance',
                    'view-hr-reports',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'Payroll Specialist',
                'slug' => 'payroll-specialist',
                'description' => 'Processes payroll and generates payslips',
                'category' => 'HR',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-employees',
                    'view-payroll',
                    'process-payroll',
                    'generate-payslip',
                    'view-dashboard',
                ],
            ],

            // Operations
            [
                'name' => 'Operations Manager',
                'slug' => 'operations-manager',
                'description' => 'Oversees daily business operations',
                'category' => 'Operations',
                'recommended_role' => 'Manager',
                'is_active' => true,
                'permissions' => [
                    'view-customers',
                    'view-invoices',
                    'view-employees',
                    'view-attendance',
                    'view-products',
                    'view-dashboard',
                    'view-finance-reports',
                ],
            ],
            [
                'name' => 'Operations Staff',
                'slug' => 'operations-staff',
                'description' => 'Supports operational tasks and processes',
                'category' => 'Operations',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-customers',
                    'view-invoices',
                    'view-employees',
                    'view-products',
                    'view-dashboard',
                ],
            ],

            // Sales
            [
                'name' => 'Sales Manager',
                'slug' => 'sales-manager',
                'description' => 'Leads sales team and manages sales operations',
                'category' => 'Sales',
                'recommended_role' => 'Manager',
                'is_active' => true,
                'permissions' => [
                    'view-customers',
                    'create-customers',
                    'edit-customers',
                    'view-invoices',
                    'create-invoices',
                    'edit-invoices',
                    'send-invoices',
                    'view-products',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'Sales Representative',
                'slug' => 'sales-representative',
                'description' => 'Manages customer accounts and sales',
                'category' => 'Sales',
                'recommended_role' => 'Staff',
                'is_active' => true,
                'permissions' => [
                    'view-customers',
                    'create-customers',
                    'edit-customers',
                    'view-invoices',
                    'create-invoices',
                    'send-invoices',
                    'view-products',
                    'view-dashboard',
                ],
            ],
        ];

        foreach ($positions as $position) {
            $permissions = $position['permissions'] ?? [];
            unset($position['permissions']);

            // Create or get position template
            $template = PositionTemplate::firstOrCreate(
                ['slug' => $position['slug']],
                $position
            );

            // Clear existing permissions
            PositionTemplatePermission::where('position_template_id', $template->id)->delete();

            // Attach permissions
            foreach ($permissions as $permission) {
                PositionTemplatePermission::create([
                    'position_template_id' => $template->id,
                    'permission_name' => $permission,
                ]);
            }

            $this->command->line("✓ Created position: {$position['name']} with " . count($permissions) . " permissions");
        }

        $this->command->info('Position seeding completed!');
    }
}
