<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RepairService;
use App\Models\User;

class RepairServiceTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get a user to set as creator
        $user = User::where('role', 'Manager')->orWhere('role', 'Staff')->first();
        
        if (!$user) {
            $user = User::first();
        }

        if (!$user) {
            $this->command->error('No users found. Please create a user first.');
            return;
        }

        // Create sample repair services with different statuses
        $services = [
            [
                'name' => 'Deep Clean',
                'category' => 'Care',
                'old_price' => 350.00,
                'price' => 850.00,
                'duration' => '30-45 min',
                'description' => 'Increased cleaning material costs',
                'status' => 'Under Review',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'name' => 'Premium Restoration',
                'category' => 'Restoration',
                'old_price' => 1000.00,
                'price' => 1500.00,
                'duration' => '2-3 days',
                'description' => 'New specialized restoration equipment investment',
                'status' => 'Under Review',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'name' => 'Color Touch-Up',
                'category' => 'Restoration',
                'old_price' => 500.00,
                'price' => 650.00,
                'duration' => '1-2 hours',
                'description' => 'Premium dye cost increase',
                'status' => 'Under Review',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'name' => 'Sole Replacement',
                'category' => 'Repair',
                'old_price' => 900.00,
                'price' => 1200.00,
                'duration' => '3-5 days',
                'description' => 'Quality rubber material price adjustment',
                'status' => 'Pending Owner Approval',
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'finance_reviewed_by' => $user->id,
                'finance_reviewed_at' => now()->subDays(1),
                'finance_notes' => 'Margin is acceptable, good quality materials.',
            ],
            [
                'name' => 'Basic Cleaning',
                'category' => 'Care',
                'old_price' => 400.00,
                'price' => 450.00,
                'duration' => '15-20 min',
                'description' => 'Standard service price update',
                'status' => 'Active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'finance_reviewed_by' => $user->id,
                'finance_reviewed_at' => now()->subDays(3),
                'finance_notes' => 'Approved for competitive pricing.',
                'owner_reviewed_at' => now()->subDays(2),
            ],
        ];

        foreach ($services as $serviceData) {
            RepairService::create($serviceData);
        }

        $this->command->info('Sample repair services created successfully!');
    }
}
