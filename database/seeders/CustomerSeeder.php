<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Customer 1: Active customer from Makati City
        User::updateOrCreate(
            ['email' => 'miguel.rosa@example.com'],
            [
                'first_name' => 'Miguel',
                'last_name' => 'Dela Rosa',
                'name' => 'Miguel Dela Rosa',
                'phone' => '+639124567801',
                'age' => 28,
                'address' => '124 P. Burgos Street, Makati City',
                'password' => bcrypt('Password123'), // Valid: uppercase, lowercase, number, 8+ chars
                'status' => 'active',
                'role' => null, // Regular customer (not staff)
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/miguel_dela_rosa_id.jpg',
            ]
        );

        // Customer 2: Active customer from Quezon City
        User::updateOrCreate(
            ['email' => 'maria.santos@example.com'],
            [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'name' => 'Maria Santos',
                'phone' => '+639171234567',
                'age' => 25,
                'address' => '789 Retail Plaza, Quezon City',
                'password' => bcrypt('SecurePass2024'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/maria_santos_id.jpg',
            ]
        );

        // Customer 3: Active customer from Pasay City
        User::updateOrCreate(
            ['email' => 'john.tan@example.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Tan',
                'name' => 'John Tan',
                'phone' => '+639189876543',
                'age' => 32,
                'address' => '321 Mall of Asia Complex, Pasay City',
                'password' => bcrypt('MyPass789'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/john_tan_id.pdf',
            ]
        );

        // Customer 4: Active customer from Taguig City
        User::updateOrCreate(
            ['email' => 'roberto.cruz@example.com'],
            [
                'first_name' => 'Roberto',
                'last_name' => 'Cruz',
                'name' => 'Roberto Cruz',
                'phone' => '+639195551234',
                'age' => 45,
                'address' => '555 Service Street, Taguig City',
                'password' => bcrypt('Roberto2024!'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/roberto_cruz_id.jpg',
            ]
        );

        // Customer 5: Active customer from Mandaluyong City
        User::updateOrCreate(
            ['email' => 'patricia.reyes@example.com'],
            [
                'first_name' => 'Patricia',
                'last_name' => 'Reyes',
                'name' => 'Patricia Reyes',
                'phone' => '+639176667890',
                'age' => 29,
                'address' => '888 Industrial Park, Mandaluyong City',
                'password' => bcrypt('Patricia123'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/patricia_reyes_id.png',
            ]
        );

        // Customer 6: Active customer from Metro Manila
        User::updateOrCreate(
            ['email' => 'carlos.mendoza@example.com'],
            [
                'first_name' => 'Carlos',
                'last_name' => 'Mendoza',
                'name' => 'Carlos Mendoza',
                'phone' => '+639123456789',
                'age' => 35,
                'address' => '456 Commerce Ave, Metro Manila',
                'password' => bcrypt('Carlos2024Pass'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/carlos_mendoza_id.jpg',
            ]
        );

        // Customer 7: Active young customer
        User::updateOrCreate(
            ['email' => 'anna.garcia@example.com'],
            [
                'first_name' => 'Anna',
                'last_name' => 'Garcia',
                'name' => 'Anna Garcia',
                'phone' => '+639987654321',
                'age' => 22,
                'address' => '101 University Ave, Manila',
                'password' => bcrypt('AnnaGarcia99'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/anna_garcia_id.jpg',
            ]
        );

        // Customer 8: Active senior customer
        User::updateOrCreate(
            ['email' => 'eduardo.lopez@example.com'],
            [
                'first_name' => 'Eduardo',
                'last_name' => 'Lopez',
                'name' => 'Eduardo Lopez',
                'phone' => '+639156781234',
                'age' => 58,
                'address' => '234 Retirement Village, Las Piñas',
                'password' => bcrypt('Eduardo1965'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/eduardo_lopez_id.pdf',
            ]
        );

        // Customer 9: Suspended customer (for testing)
        User::updateOrCreate(
            ['email' => 'suspended.customer@example.com'],
            [
                'first_name' => 'Suspended',
                'last_name' => 'Customer',
                'name' => 'Suspended Customer',
                'phone' => '+639111222333',
                'age' => 30,
                'address' => '999 Test Street, Pasig City',
                'password' => bcrypt('SuspendTest123'), // Valid password
                'status' => 'suspended',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/suspended_customer_id.jpg',
            ]
        );

        // Customer 10: Inactive customer (for testing)
        User::updateOrCreate(
            ['email' => 'inactive.customer@example.com'],
            [
                'first_name' => 'Inactive',
                'last_name' => 'Customer',
                'name' => 'Inactive Customer',
                'phone' => '+639222333444',
                'age' => 27,
                'address' => '777 Test Avenue, Muntinlupa',
                'password' => bcrypt('InactiveTest456'), // Valid password
                'status' => 'inactive',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'valid_id_path' => 'valid_ids/inactive_customer_id.jpg',
            ]
        );

        // Customer 11: Newly verified customer (no last login)
        User::updateOrCreate(
            ['email' => 'newbie.customer@example.com'],
            [
                'first_name' => 'Newbie',
                'last_name' => 'Customer',
                'name' => 'Newbie Customer',
                'phone' => '+639333444555',
                'age' => 21,
                'address' => '888 Startup Street, BGC Taguig',
                'password' => bcrypt('Newbie2024Pass'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'last_login_at' => null,
                'last_login_ip' => null,
                'valid_id_path' => 'valid_ids/newbie_customer_id.jpg',
            ]
        );

        // Customer 12: Frequent customer with last login
        User::updateOrCreate(
            ['email' => 'frequent.customer@example.com'],
            [
                'first_name' => 'Frequent',
                'last_name' => 'Buyer',
                'name' => 'Frequent Buyer',
                'phone' => '+639444555666',
                'age' => 38,
                'address' => '555 Shopping District, Ortigas',
                'password' => bcrypt('FrequentBuy99'), // Valid password
                'status' => 'active',
                'role' => null,
                'shop_owner_id' => null,
                'email_verified_at' => now(),
                'last_login_at' => now()->subHours(2),
                'last_login_ip' => '192.168.1.100',
                'valid_id_path' => 'valid_ids/frequent_buyer_id.jpg',
            ]
        );
    }
}
