<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OwnerErpTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_read_pages_only_return_data_from_the_authenticated_tenant(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach ([$owner, $otherOwner] as $shopOwner) {
            foreach (['crm', 'inventory', 'procurement'] as $moduleKey) {
                ShopOwnerModule::factory()->create([
                    'shop_owner_id' => $shopOwner->id,
                    'module_key' => $moduleKey,
                    'enabled' => true,
                ]);
            }
        }

        $otherInventory = InventoryItem::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other tenant stock',
        ]);
        $otherSupplier = Supplier::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other tenant supplier',
        ]);
        Order::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'customer_name' => 'Other tenant customer',
            'customer_email' => 'other-tenant@example.test',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/inventory/product-inventory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('initialData.data', fn ($rows): bool => collect($rows)
                    ->doesntContain(fn (array $row): bool => (int) $row['id'] === $otherInventory->id))
            );

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/procurement/suppliers-management')
            ->assertInertia(fn (Assert $page) => $page
                ->where('initialData.data', fn ($rows): bool => collect($rows)
                    ->doesntContain(fn (array $row): bool => (int) $row['id'] === $otherSupplier->id))
            );

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/staff/customers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('initialCustomers', fn ($customers): bool => collect($customers)
                    ->doesntContain(fn (array $customer): bool => $customer['email'] === 'other-tenant@example.test'))
            );
    }
}
