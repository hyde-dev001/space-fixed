<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\User;
use App\Models\ShopOwner;
use Illuminate\Support\Facades\DB;

class TestOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first shop owner
        $shopOwner = ShopOwner::first();
        
        if (!$shopOwner) {
            $this->command->warn('No shop owner found. Please create a shop owner first.');
            return;
        }

        // Create test customers if they don't exist
        $customers = [];
        
        $customerData = [
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.delacruz@example.com',
                'phone' => '09123456789',
                'address' => '123 Main St, Manila, Philippines',
            ],
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@example.com',
                'phone' => '09234567890',
                'address' => '456 Oak Ave, Quezon City, Philippines',
            ],
            [
                'name' => 'Pedro Reyes',
                'email' => 'pedro.reyes@example.com',
                'phone' => '09345678901',
                'address' => '789 Pine Rd, Makati, Philippines',
            ],
            [
                'name' => 'Ana Garcia',
                'email' => 'ana.garcia@example.com',
                'phone' => '09456789012',
                'address' => '321 Elm St, Pasig, Philippines',
            ],
            [
                'name' => 'Jose Ramos',
                'email' => 'jose.ramos@example.com',
                'phone' => '09567890123',
                'address' => '654 Birch Ln, Taguig, Philippines',
            ],
        ];

        foreach ($customerData as $data) {
            $customer = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'password' => bcrypt('password123'),
                    'role' => null, // Customers don't have a role in the system
                ]
            );
            $customers[] = $customer;
        }

        // Create multiple orders for each customer
        $orderStatuses = ['pending', 'processing', 'shipped', 'delivered'];
        $paymentStatuses = ['paid', 'pending'];
        
        $orderNumber = 1000;
        
        foreach ($customers as $index => $customer) {
            // Create 3-5 orders per customer with different dates
            $numberOfOrders = rand(3, 5);
            
            for ($i = 0; $i < $numberOfOrders; $i++) {
                $daysAgo = rand(1, 120);
                $createdAt = now()->subDays($daysAgo);
                
                Order::create([
                    'shop_owner_id' => $shopOwner->id,
                    'customer_id' => $customer->id,
                    'order_number' => 'ORD-' . str_pad($orderNumber++, 6, '0', STR_PAD_LEFT),
                    'customer_name' => $customer->name,
                    'customer_email' => $customer->email,
                    'customer_phone' => $customer->phone,
                    'customer_address' => $customer->address,
                    'total_amount' => rand(5000, 50000),
                    'status' => $orderStatuses[array_rand($orderStatuses)],
                    'payment_method' => 'online',
                    'payment_status' => $paymentStatuses[array_rand($paymentStatuses)],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }

        $this->command->info('Test orders created successfully!');
        $this->command->info('Created orders for ' . count($customers) . ' customers.');
    }
}
