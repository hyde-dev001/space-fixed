<?php

namespace Database\Seeders;

use App\Models\ShopOwner;
use Illuminate\Database\Seeder;

class ShopOwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or update a test shop owner account for login testing (idempotent)
        ShopOwner::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'first_name' => 'Test',
                'last_name' => 'ShopOwner',
                'phone' => '+1234567890',
                'password' => bcrypt('password'),
                'business_name' => 'Test Business',
                'business_address' => '123 Test Street, Test City',
                'business_type' => 'retail',
                'registration_type' => 'individual',
                'operating_hours' => json_encode([
                    'monday' => ['open' => '09:00', 'close' => '17:00'],
                    'tuesday' => ['open' => '09:00', 'close' => '17:00'],
                    'wednesday' => ['open' => '09:00', 'close' => '17:00'],
                    'thursday' => ['open' => '09:00', 'close' => '17:00'],
                    'friday' => ['open' => '09:00', 'close' => '17:00'],
                    'saturday' => ['open' => '09:00', 'close' => '17:00'],
                    'sunday' => ['open' => '10:00', 'close' => '16:00'],
                ]),
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );

        // Create second test shop owner
        ShopOwner::updateOrCreate(
            ['email' => 'test2@example.com'],
            [
                'first_name' => 'Second',
                'last_name' => 'TestShop',
                'phone' => '+0987654321',
                'password' => bcrypt('password'),
                'business_name' => 'Urban Kicks Store',
                'business_address' => '456 Commerce Ave, Metro Manila',
                'business_type' => 'both',
                'registration_type' => 'company',
                'operating_hours' => json_encode([
                    'monday' => ['open' => '10:00', 'close' => '20:00'],
                    'tuesday' => ['open' => '10:00', 'close' => '20:00'],
                    'wednesday' => ['open' => '10:00', 'close' => '20:00'],
                    'thursday' => ['open' => '10:00', 'close' => '20:00'],
                    'friday' => ['open' => '10:00', 'close' => '22:00'],
                    'saturday' => ['open' => '10:00', 'close' => '22:00'],
                    'sunday' => ['open' => '10:00', 'close' => '20:00'],
                ]),
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );

        // Create 5 pending shop owners
        ShopOwner::factory(5)->pending()->create();

        // Create 2 approved shop owners (since we added one test account)
        ShopOwner::factory(2)->approved()->create();

        // Create 2 rejected shop owners
        ShopOwner::factory(2)->rejected()->create();
    }
}
