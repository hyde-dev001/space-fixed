<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PositionTemplate;
use App\Models\PositionTemplatePermission;

/**
 * Position Templates Seeder
 * 
 * Creates predefined position templates with permission sets
 * for common job positions in the organization
 */
class PositionTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding position templates...');

        // Clear existing templates
        PositionTemplate::query()->delete();
        PositionTemplatePermission::query()->delete();

        $templates = [
            // ===== FINANCE POSITIONS =====
            [
                'name' => 'Cashier',
                'description' => 'Handles customer payments and basic invoicing',
                'category' => 'Finance',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-invoices',
                    'create-invoices',
                    'send-invoices',
                    'view-customers',
                    'view-products',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'Bookkeeper',
                'description' => 'Manages daily financial records and transactions',
                'category' => 'Finance',
                'recommended_role' => 'Staff',
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
                'name' => 'Accountant',
                'description' => 'Full finance management with reporting capabilities',
                'category' => 'Finance',
                'recommended_role' => 'Staff',
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

            // ===== HR/PAYROLL POSITIONS =====
            [
                'name' => 'Payroll Specialist',
                'description' => 'Dedicated to payroll processing and payslip generation only',
                'category' => 'HR',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-employees',
                    'view-payroll',
                    'process-payroll',
                    'generate-payslip',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'HR Assistant',
                'description' => 'Manages employee records and basic HR tasks',
                'category' => 'HR',
                'recommended_role' => 'Staff',
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
                'name' => 'HR Officer',
                'description' => 'Full HR management including attendance and leave',
                'category' => 'HR',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-employees',
                    'create-employees',
                    'edit-employees',
                    'view-attendance',
                    'create-attendance',
                    'edit-attendance',
                    'approve-timeoff',
                    'view-payroll',
                    'view-hr-reports',
                    'export-hr-reports',
                    'view-hr-audit-logs',
                    'view-dashboard',
                ],
            ],

            // ===== SALES/CRM POSITIONS =====
            [
                'name' => 'Sales Representative',
                'description' => 'Manages customer relationships and sales activities',
                'category' => 'Sales',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-customers',
                    'create-customers',
                    'edit-customers',
                    'view-leads',
                    'create-leads',
                    'edit-leads',
                    'view-opportunities',
                    'create-opportunities',
                    'view-invoices',
                    'create-invoices',
                    'send-invoices',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'CRM Specialist',
                'description' => 'Full CRM access including lead conversion and reporting',
                'category' => 'Sales',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-customers',
                    'create-customers',
                    'edit-customers',
                    'view-leads',
                    'create-leads',
                    'edit-leads',
                    'convert-leads',
                    'assign-leads',
                    'view-opportunities',
                    'create-opportunities',
                    'edit-opportunities',
                    'close-opportunities',
                    'view-crm-reports',
                    'export-crm-reports',
                    'view-dashboard',
                ],
            ],

            // ===== OPERATIONS POSITIONS =====
            [
                'name' => 'Store Clerk',
                'description' => 'Basic job order and customer service tasks',
                'category' => 'Operations',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-job-orders',
                    'create-job-orders',
                    'edit-job-orders',
                    'view-customers',
                    'view-products',
                    'view-dashboard',
                ],
            ],
            [
                'name' => 'Inventory Clerk',
                'description' => 'Manages inventory and product records',
                'category' => 'Operations',
                'recommended_role' => 'Staff',
                'permissions' => [
                    'view-products',
                    'create-products',
                    'edit-products',
                    'manage-inventory',
                    'view-job-orders',
                    'view-dashboard',
                ],
            ],

            // ===== MANAGER POSITIONS =====
            [
                'name' => 'Store Manager',
                'description' => 'Full access to all system features and management',
                'category' => 'Management',
                'recommended_role' => 'Manager',
                'permissions' => [], // Managers get all permissions automatically
            ],
            [
                'name' => 'Finance Manager',
                'description' => 'Full finance and approval authority',
                'category' => 'Management',
                'recommended_role' => 'Manager',
                'permissions' => [],
            ],
            [
                'name' => 'HR Manager',
                'description' => 'Full HR and payroll management authority',
                'category' => 'Management',
                'recommended_role' => 'Manager',
                'permissions' => [],
            ],
        ];

        foreach ($templates as $templateData) {
            $permissions = $templateData['permissions'];
            unset($templateData['permissions']);

            $templateData['slug'] = \Illuminate\Support\Str::slug($templateData['name']);

            $template = PositionTemplate::create($templateData);

            foreach ($permissions as $permissionName) {
                PositionTemplatePermission::create([
                    'position_template_id' => $template->id,
                    'permission_name' => $permissionName,
                ]);
            }

            $this->command->info("✓ Created position: {$template->name} ({$template->category})");
        }

        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('✅ Position templates seeded successfully!');
        $this->command->info('========================================');
        $this->command->info('Total Templates: ' . PositionTemplate::count());
        $this->command->info('');
        $this->command->info('Categories:');
        $categories = PositionTemplate::select('category')->distinct()->pluck('category');
        foreach ($categories as $category) {
            $count = PositionTemplate::where('category', $category)->count();
            $this->command->info("  - {$category}: {$count} positions");
        }
        $this->command->info('========================================');
    }
}
