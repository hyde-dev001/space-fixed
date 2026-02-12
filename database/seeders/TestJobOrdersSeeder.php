<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TestJobOrdersSeeder extends Seeder
{
    public function run(): void
    {
        // Find a shop owner ID
        $shopOwner = DB::table('shop_owners')->first();
        if (!$shopOwner) {
            $this->command->error('❌ No shop_owners found. Please seed shop_owners first.');
            return;
        }
        $shopOwnerId = $shopOwner->id;

        // Find or create customers
        $customer1 = DB::table('users')->where('email', 'john.smith@example.com')->first();
        if (!$customer1) {
            $customer1Id = DB::table('users')->insertGetId([
                'name' => 'John Smith',
                'email' => 'john.smith@example.com',
                'password' => bcrypt('password'),
                'role' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $customer1Id = $customer1->id;
        }

        $customer2 = DB::table('users')->where('email', 'sarah.j@example.com')->first();
        if (!$customer2) {
            $customer2Id = DB::table('users')->insertGetId([
                'name' => 'Sarah Johnson',
                'email' => 'sarah.j@example.com',
                'password' => bcrypt('password'),
                'role' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $customer2Id = $customer2->id;
        }

        $customer3 = DB::table('users')->where('email', 'mchen@example.com')->first();
        if (!$customer3) {
            $customer3Id = DB::table('users')->insertGetId([
                'name' => 'Michael Chen',
                'email' => 'mchen@example.com',
                'password' => bcrypt('password'),
                'role' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $customer3Id = $customer3->id;
        }

        $customer4 = DB::table('users')->where('email', 'emma.w@example.com')->first();
        if (!$customer4) {
            $customer4Id = DB::table('users')->insertGetId([
                'name' => 'Emma Wilson',
                'email' => 'emma.w@example.com',
                'password' => bcrypt('password'),
                'role' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $customer4Id = $customer4->id;
        }

        $customer5 = DB::table('users')->where('email', 'robert.b@example.com')->first();
        if (!$customer5) {
            $customer5Id = DB::table('users')->insertGetId([
                'name' => 'Robert Brown',
                'email' => 'robert.b@example.com',
                'password' => bcrypt('password'),
                'role' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $customer5Id = $customer5->id;
        }

        $customer6 = DB::table('users')->where('email', 'lisa.d@example.com')->first();
        if (!$customer6) {
            $customer6Id = DB::table('users')->insertGetId([
                'name' => 'Lisa Davis',
                'email' => 'lisa.d@example.com',
                'password' => bcrypt('password'),
                'role' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $customer6Id = $customer6->id;
        }

        // Create orders with correct schema
        DB::table('orders')->insert([
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer1Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-001',
                'total_amount' => 299.99,
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer2Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-002',
                'total_amount' => 149.99,
                'status' => 'processing',
                'created_at' => Carbon::now()->subDays(1),
                'updated_at' => Carbon::now(),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer3Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-003',
                'total_amount' => 499.99,
                'status' => 'pending',
                'created_at' => Carbon::now()->subHours(3),
                'updated_at' => Carbon::now(),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer1Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-004',
                'total_amount' => 399.99,
                'status' => 'completed',
                'created_at' => Carbon::now()->subDays(3),
                'updated_at' => Carbon::now()->subDays(1),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer2Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-005',
                'total_amount' => 199.99,
                'status' => 'completed',
                'created_at' => Carbon::now()->subDays(7),
                'updated_at' => Carbon::now()->subDays(2),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer4Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-006',
                'total_amount' => 179.99,
                'status' => 'pending',
                'created_at' => Carbon::now()->subHours(6),
                'updated_at' => Carbon::now()->subHours(6),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer5Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-007',
                'total_amount' => 329.99,
                'status' => 'processing',
                'created_at' => Carbon::now()->subDays(2),
                'updated_at' => Carbon::now()->subHours(12),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer6Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-008',
                'total_amount' => 599.99,
                'status' => 'pending',
                'created_at' => Carbon::now()->subHours(2),
                'updated_at' => Carbon::now()->subHours(2),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer3Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-009',
                'total_amount' => 249.99,
                'status' => 'processing',
                'created_at' => Carbon::now()->subDays(1)->subHours(6),
                'updated_at' => Carbon::now()->subHours(3),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer4Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-010',
                'total_amount' => 449.99,
                'status' => 'pending',
                'created_at' => Carbon::now()->subHours(1),
                'updated_at' => Carbon::now()->subHours(1),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer5Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-011',
                'total_amount' => 189.99,
                'status' => 'completed',
                'created_at' => Carbon::now()->subDays(10),
                'updated_at' => Carbon::now()->subDays(5),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer1Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-012',
                'total_amount' => 799.99,
                'status' => 'pending',
                'created_at' => Carbon::now()->subMinutes(45),
                'updated_at' => Carbon::now()->subMinutes(45),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer6Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-013',
                'total_amount' => 359.99,
                'status' => 'processing',
                'created_at' => Carbon::now()->subHours(18),
                'updated_at' => Carbon::now()->subHours(8),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer2Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-014',
                'total_amount' => 129.99,
                'status' => 'pending',
                'created_at' => Carbon::now()->subHours(4),
                'updated_at' => Carbon::now()->subHours(4),
            ],
            [
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => $customer3Id,
                'order_number' => 'ORD-' . Carbon::now()->format('YmdHis') . '-015',
                'total_amount' => 899.99,
                'status' => 'completed',
                'created_at' => Carbon::now()->subDays(14),
                'updated_at' => Carbon::now()->subDays(8),
            ],
        ]);

        $this->command->info('✅ Created 15 test job orders');
        $this->command->info('   - 6 pending orders');
        $this->command->info('   - 4 processing orders');
        $this->command->info('   - 5 completed orders');
    }
}
