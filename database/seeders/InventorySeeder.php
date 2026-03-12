<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShopOwner;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\InventoryColorVariant;
use App\Models\InventoryImage;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;

class InventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create a shop owner
        $shopOwner = ShopOwner::first();
        
        if (!$shopOwner) {
            $this->command->error('No shop owner found. Please create a shop owner first.');
            return;
        }

        $this->command->info('Seeding inventory data for shop owner: ' . $shopOwner->shop_name);

        // Create suppliers
        $this->command->info('Creating suppliers...');
        $suppliers = [];
        $supplierData = [
            ['name' => 'Prime Shoe Goods', 'city' => 'Manila'],
            ['name' => 'Metro Footwear Trading', 'city' => 'Quezon City'],
            ['name' => 'CleanKicks Supply Co.', 'city' => 'Makati'],
            ['name' => 'Urban Streetwear Partners', 'city' => 'Pasig'],
        ];

        foreach ($supplierData as $data) {
            $suppliers[] = Supplier::create([
                'shop_owner_id' => $shopOwner->id,
                'name' => $data['name'],
                'contact_person' => fake()->name(),
                'email' => fake()->companyEmail(),
                'phone' => fake()->phoneNumber(),
                'address' => fake()->streetAddress(),
                'city' => $data['city'],
                'country' => 'Philippines',
                'payment_terms' => fake()->randomElement(['Net 30', 'Net 60', 'COD']),
                'lead_time_days' => rand(5, 15),
                'is_active' => true,
            ]);
        }

        // Create inventory items
        $this->command->info('Creating inventory items...');
        $inventoryData = [
            [
                'name' => 'Nike Air Max 270',
                'sku' => 'NK-AM270-BLK',
                'category' => 'shoes',
                'brand' => 'Nike',
                'quantity' => 42,
                'price' => 6499,
                'cost_price' => 4500,
            ],
            [
                'name' => 'Adidas Ultraboost 22',
                'sku' => 'AD-UB22-WHT',
                'category' => 'shoes',
                'brand' => 'Adidas',
                'quantity' => 11,
                'price' => 8999,
                'cost_price' => 6500,
            ],
            [
                'name' => 'New Balance 550',
                'sku' => 'NB-550-GRY',
                'category' => 'shoes',
                'brand' => 'New Balance',
                'quantity' => 0,
                'price' => 5299,
                'cost_price' => 3800,
            ],
            [
                'name' => 'Puma RS-X',
                'sku' => 'PM-RSX-RED',
                'category' => 'shoes',
                'brand' => 'Puma',
                'quantity' => 8,
                'price' => 4799,
                'cost_price' => 3200,
            ],
            [
                'name' => 'Premium Shoelaces',
                'sku' => 'ACC-LACE-PRM',
                'category' => 'accessories',
                'brand' => 'Solespace',
                'quantity' => 120,
                'price' => 180,
                'cost_price' => 80,
            ],
            [
                'name' => 'Cleaning Foam',
                'sku' => 'CARE-FOAM-CLN',
                'category' => 'care_products',
                'brand' => 'Solespace',
                'quantity' => 9,
                'price' => 220,
                'cost_price' => 120,
            ],
            [
                'name' => 'Leather Conditioner',
                'sku' => 'CARE-LTH-250',
                'category' => 'care_products',
                'brand' => 'Angelus',
                'quantity' => 27,
                'price' => 350,
                'cost_price' => 200,
            ],
            [
                'name' => 'Shoe Box (Large)',
                'sku' => 'PKG-BOX-L',
                'category' => 'packaging',
                'brand' => 'Solespace',
                'quantity' => 6,
                'price' => 50,
                'cost_price' => 25,
            ],
        ];

        $items = [];
        foreach ($inventoryData as $data) {
            $item = InventoryItem::create([
                'shop_owner_id' => $shopOwner->id,
                'name' => $data['name'],
                'sku' => $data['sku'],
                'category' => $data['category'],
                'brand' => $data['brand'],
                'description' => fake()->paragraph(),
                'unit' => $data['category'] === 'shoes' ? 'pairs' : ($data['category'] === 'accessories' ? 'pcs' : 'bottles'),
                'available_quantity' => $data['quantity'],
                'reserved_quantity' => rand(0, 5),
                'reorder_level' => 10,
                'reorder_quantity' => 50,
                'price' => $data['price'],
                'cost_price' => $data['cost_price'],
                'weight' => rand(100, 1500) / 100,
                'is_active' => true,
            ]);

            // Add initial stock movement
            StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type' => 'initial',
                'quantity_change' => $data['quantity'],
                'quantity_before' => 0,
                'quantity_after' => $data['quantity'],
                'notes' => 'Initial stock',
                'performed_at' => now()->subDays(rand(1, 30)),
            ]);

            // Add sizes for shoes
            if ($data['category'] === 'shoes') {
                $sizes = ['7', '8', '9', '10', '11'];
                foreach ($sizes as $size) {
                    InventorySize::create([
                        'inventory_item_id' => $item->id,
                        'size' => $size,
                        'quantity' => rand(0, 10),
                    ]);
                }

                // Add color variants
                $colors = [
                    ['name' => 'Black', 'code' => '#000000'],
                    ['name' => 'White', 'code' => '#FFFFFF'],
                    ['name' => 'Red', 'code' => '#FF0000'],
                ];

                foreach ($colors as $color) {
                    $variant = InventoryColorVariant::create([
                        'inventory_item_id' => $item->id,
                        'color_name' => $color['name'],
                        'color_code' => $color['code'],
                        'quantity' => rand(5, 20),
                        'sku_suffix' => strtoupper(substr($color['name'], 0, 3)),
                    ]);
                }
            }

            $items[] = $item;
        }

        // Create some stock movements
        $this->command->info('Creating stock movements...');
        $users = User::limit(3)->get();
        $movementTypes = ['stock_in', 'stock_out', 'adjustment', 'return', 'repair_usage'];

        foreach ($items as $item) {
            for ($i = 0; $i < rand(3, 7); $i++) {
                $type = fake()->randomElement($movementTypes);
                $quantityChange = rand(1, 10);
                
                if (in_array($type, ['stock_out', 'repair_usage'])) {
                    $quantityChange = -$quantityChange;
                }

                $quantityBefore = $item->available_quantity;
                $quantityAfter = max(0, $quantityBefore + $quantityChange);

                StockMovement::create([
                    'inventory_item_id' => $item->id,
                    'movement_type' => $type,
                    'quantity_change' => $quantityChange,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'notes' => fake()->sentence(),
                    'performed_by' => $users->random()->id ?? null,
                    'performed_at' => now()->subDays(rand(1, 25)),
                ]);

                $item->available_quantity = $quantityAfter;
            }
        }

        // Create supplier orders
        $this->command->info('Creating supplier orders...');
        $orderStatuses = ['sent', 'confirmed', 'in_transit', 'delivered'];
        
        for ($i = 1; $i <= 6; $i++) {
            $supplier = fake()->randomElement($suppliers);
            $orderDate = now()->subDays(rand(1, 20));
            $expectedDate = now()->addDays(rand(-5, 10));
            
            $order = SupplierOrder::create([
                'shop_owner_id' => $shopOwner->id,
                'supplier_id' => $supplier->id,
                'po_number' => 'PO-2026-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'status' => fake()->randomElement($orderStatuses),
                'order_date' => $orderDate,
                'expected_delivery_date' => $expectedDate,
                'currency' => 'PHP',
                'payment_status' => fake()->randomElement(['unpaid', 'partial', 'paid']),
                'remarks' => fake()->sentence(),
            ]);

            // Add order items
            $numItems = rand(1, 3);
            $totalAmount = 0;
            
            for ($j = 0; $j < $numItems; $j++) {
                $item = fake()->randomElement($items);
                $quantity = rand(10, 50);
                $unitPrice = $item->cost_price;
                $totalPrice = $quantity * $unitPrice;
                $totalAmount += $totalPrice;

                SupplierOrderItem::create([
                    'supplier_order_id' => $order->id,
                    'inventory_item_id' => $item->id,
                    'product_name' => $item->name,
                    'sku' => $item->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'quantity_received' => $order->status === 'delivered' ? $quantity : 0,
                ]);
            }

            $order->update(['total_amount' => $totalAmount]);
        }

        $this->command->info('Inventory data seeded successfully!');
    }
}
