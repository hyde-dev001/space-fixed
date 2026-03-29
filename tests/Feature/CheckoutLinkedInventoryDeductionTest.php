<?php

namespace Tests\Feature;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckoutLinkedInventoryDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('MySQL test profile requires pdo_mysql extension.');
        }

        $testDatabase = getenv('MYSQL_TEST_DATABASE') ?: ($_ENV['MYSQL_TEST_DATABASE'] ?? null);
        if (!$testDatabase) {
            $this->markTestSkipped('Set MYSQL_TEST_DATABASE to run this test on MySQL test profile.');
        }

        $this->setMysqlTestingEnvironment((string) $testDatabase);

        parent::setUp();
    }

    private function setMysqlTestingEnvironment(string $database): void
    {
        $connection = getenv('MYSQL_TEST_CONNECTION') ?: ($_ENV['MYSQL_TEST_CONNECTION'] ?? 'mysql');
        $host = getenv('MYSQL_TEST_HOST') ?: ($_ENV['MYSQL_TEST_HOST'] ?? (getenv('DB_HOST') ?: '127.0.0.1'));
        $port = getenv('MYSQL_TEST_PORT') ?: ($_ENV['MYSQL_TEST_PORT'] ?? (getenv('DB_PORT') ?: '3306'));
        $username = getenv('MYSQL_TEST_USERNAME') ?: ($_ENV['MYSQL_TEST_USERNAME'] ?? (getenv('DB_USERNAME') ?: 'root'));
        $password = getenv('MYSQL_TEST_PASSWORD') ?: ($_ENV['MYSQL_TEST_PASSWORD'] ?? (getenv('DB_PASSWORD') ?: ''));

        putenv("DB_CONNECTION={$connection}");
        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_DATABASE={$database}");
        putenv("DB_USERNAME={$username}");
        putenv("DB_PASSWORD={$password}");

        $_ENV['DB_CONNECTION'] = $connection;
        $_ENV['DB_HOST'] = $host;
        $_ENV['DB_PORT'] = $port;
        $_ENV['DB_DATABASE'] = $database;
        $_ENV['DB_USERNAME'] = $username;
        $_ENV['DB_PASSWORD'] = $password;

        $_SERVER['DB_CONNECTION'] = $connection;
        $_SERVER['DB_HOST'] = $host;
        $_SERVER['DB_PORT'] = $port;
        $_SERVER['DB_DATABASE'] = $database;
        $_SERVER['DB_USERNAME'] = $username;
        $_SERVER['DB_PASSWORD'] = $password;
    }

    private function createShopOwner(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'both',
            'registration_type' => 'individual',
        ], $overrides));
    }

    #[Test]
    public function checkout_decrements_linked_inventory_and_product_stock_for_variant_items(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->createOne([
            'status' => 'active',
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Linked Inventory Sneaker',
            'slug' => 'linked-inventory-sneaker-' . random_int(1000, 9999),
            'description' => 'Stock-linked product for checkout test',
            'price' => 1299,
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'size' => 'US 8',
            'color' => 'Black',
            'quantity' => 10,
            'is_active' => true,
        ]);

        $inventoryItem = InventoryItem::create([
            'product_id' => $product->id,
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Linked Inventory Sneaker',
            'sku' => 'INV-LINK-1001',
            'category' => 'shoes',
            'brand' => 'TestBrand',
            'unit' => 'pairs',
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 1,
            'reorder_quantity' => 5,
            'is_active' => true,
        ]);

        $inventoryColor = InventoryColorVariant::create([
            'inventory_item_id' => $inventoryItem->id,
            'color_name' => 'Black',
            'color_code' => '#000000',
            'quantity' => 10,
        ]);

        $inventorySize = InventorySize::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_color_variant_id' => $inventoryColor->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 10,
        ]);

        $payload = [
            'items' => [
                [
                    'id' => 'cart-item-1',
                    'pid' => $product->id,
                    'qty' => 2,
                    'name' => $product->name,
                    'price' => 1299,
                    'size' => '8',
                    'color' => 'Black',
                    'image' => null,
                    'options' => [
                        'color' => 'Black',
                    ],
                ],
            ],
            'total_amount' => 2598,
            'shipping_fee' => 0,
            'customer_name' => 'Checkout Test Customer',
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'shipping_address' => '123 Test Street, Test City',
            'payment_method' => 'paymongo',
        ];

        /** @var Authenticatable $customer */
        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $payload);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $product->refresh();
        $inventoryItem->refresh();
        $inventoryColor->refresh();
        $inventorySize->refresh();

        $this->assertSame(8, (int) $product->stock_quantity);
        $this->assertSame(8, (int) $inventoryItem->available_quantity);
        $this->assertSame(8, (int) $inventoryColor->quantity);
        $this->assertSame(8, (int) $inventorySize->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -2,
            'quantity_before' => 10,
            'quantity_after' => 8,
            'reference_type' => 'order',
        ]);

        $this->assertSame(1, StockMovement::where('inventory_item_id', $inventoryItem->id)
            ->where('movement_type', 'stock_out')
            ->count());
    }
}
