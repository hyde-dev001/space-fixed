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
        // Shop Owner 1: Individual, Both retail and repair
        ShopOwner::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'first_name' => 'Test',
                'last_name' => 'ShopOwner',
                'phone' => '+1234567890',
                'password' => bcrypt('password'),
                'business_name' => 'Test Business',
                'business_address' => '123 Test Street, Test City',
                'city_state' => 'Makati',
                'country' => 'Philippines',
                'business_type' => 'both',
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

        // Shop Owner 2: Company, Both retail and repair
        ShopOwner::updateOrCreate(
            ['email' => 'test2@example.com'],
            [
                'first_name' => 'Second',
                'last_name' => 'TestShop',
                'phone' => '+0987654321',
                'password' => bcrypt('password'),
                'business_name' => 'Urban Kicks Store',
                'business_address' => '456 Commerce Ave, Metro Manila',
                'city_state' => 'Metro Manila',
                'country' => 'Philippines',
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

        // Shop Owner 3: Individual, Retail only
        ShopOwner::updateOrCreate(
            ['email' => 'retail.shop@example.com'],
            [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'phone' => '+639171234567',
                'password' => bcrypt('password'),
                'business_name' => 'Sneaker Haven',
                'business_address' => '789 Retail Plaza, Quezon City',
                'city_state' => 'Quezon City',
                'country' => 'Philippines',
                'business_type' => 'retail',
                'registration_type' => 'individual',
                'operating_hours' => json_encode([
                    'monday' => ['open' => '09:00', 'close' => '18:00'],
                    'tuesday' => ['open' => '09:00', 'close' => '18:00'],
                    'wednesday' => ['open' => '09:00', 'close' => '18:00'],
                    'thursday' => ['open' => '09:00', 'close' => '18:00'],
                    'friday' => ['open' => '09:00', 'close' => '19:00'],
                    'saturday' => ['open' => '09:00', 'close' => '19:00'],
                    'sunday' => ['open' => '11:00', 'close' => '17:00'],
                ]),
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );

        // Shop Owner 4: Company, Retail only
        ShopOwner::updateOrCreate(
            ['email' => 'premium.retail@example.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Tan',
                'phone' => '+639189876543',
                'password' => bcrypt('password'),
                'business_name' => 'Premium Footwear Corp',
                'business_address' => '321 Mall of Asia Complex, Pasay City',
                'city_state' => 'Pasay City',
                'country' => 'Philippines',
                'business_type' => 'retail',
                'registration_type' => 'company',
                'operating_hours' => json_encode([
                    'monday' => ['open' => '10:00', 'close' => '21:00'],
                    'tuesday' => ['open' => '10:00', 'close' => '21:00'],
                    'wednesday' => ['open' => '10:00', 'close' => '21:00'],
                    'thursday' => ['open' => '10:00', 'close' => '21:00'],
                    'friday' => ['open' => '10:00', 'close' => '22:00'],
                    'saturday' => ['open' => '10:00', 'close' => '22:00'],
                    'sunday' => ['open' => '10:00', 'close' => '21:00'],
                ]),
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );

        // Shop Owner 5: Individual, Repair only
        ShopOwner::updateOrCreate(
            ['email' => 'repair.expert@example.com'],
            [
                'first_name' => 'Roberto',
                'last_name' => 'Cruz',
                'phone' => '+639195551234',
                'password' => bcrypt('password'),
                'business_name' => 'Shoe Repair Expert',
                'business_address' => '555 Service Street, Taguig City',
                'city_state' => 'Taguig City',
                'country' => 'Philippines',
                'business_type' => 'repair',
                'registration_type' => 'individual',
                'operating_hours' => json_encode([
                    'monday' => ['open' => '08:00', 'close' => '17:00'],
                    'tuesday' => ['open' => '08:00', 'close' => '17:00'],
                    'wednesday' => ['open' => '08:00', 'close' => '17:00'],
                    'thursday' => ['open' => '08:00', 'close' => '17:00'],
                    'friday' => ['open' => '08:00', 'close' => '17:00'],
                    'saturday' => ['open' => '08:00', 'close' => '14:00'],
                    'sunday' => ['open' => '00:00', 'close' => '00:00'],
                ]),
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );

        // Shop Owner 6: Company, Repair only
        ShopOwner::updateOrCreate(
            ['email' => 'pro.repair@example.com'],
            [
                'first_name' => 'Patricia',
                'last_name' => 'Reyes',
                'phone' => '+639176667890',
                'password' => bcrypt('password'),
                'business_name' => 'ProShoe Restoration Services Inc.',
                'business_address' => '888 Industrial Park, Mandaluyong City',
                'city_state' => 'Mandaluyong City',
                'country' => 'Philippines',
                'business_type' => 'repair',
                'registration_type' => 'company',
                'operating_hours' => json_encode([
                    'monday' => ['open' => '07:00', 'close' => '19:00'],
                    'tuesday' => ['open' => '07:00', 'close' => '19:00'],
                    'wednesday' => ['open' => '07:00', 'close' => '19:00'],
                    'thursday' => ['open' => '07:00', 'close' => '19:00'],
                    'friday' => ['open' => '07:00', 'close' => '19:00'],
                    'saturday' => ['open' => '08:00', 'close' => '16:00'],
                    'sunday' => ['open' => '00:00', 'close' => '00:00'],
                ]),
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );
    }
}
