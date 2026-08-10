<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\InventoryItem;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopReview;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
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

    public function test_owner_read_apis_only_return_data_from_the_authenticated_tenant(): void
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
            foreach (['crm', 'logistics', 'hr_employees', 'finance', 'inventory', 'procurement'] as $moduleKey) {
                ShopOwnerModule::factory()->create([
                    'shop_owner_id' => $shopOwner->id,
                    'module_key' => $moduleKey,
                    'enabled' => true,
                ]);
            }
        }

        $otherCustomer = User::factory()->create([
            'name' => 'Other tenant API customer',
            'email' => 'other-api-customer@example.test',
        ]);
        Order::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'customer_id' => $otherCustomer->id,
        ]);
        $otherReview = ShopReview::create([
            'shop_owner_id' => $otherOwner->id,
            'user_id' => $otherCustomer->id,
            'rating' => 1,
            'comment' => 'Other tenant review',
        ]);
        $otherShipment = Shipment::factory()->create([
            'shop_owner_id' => $otherOwner->id,
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $otherOwner->id,
        ]);
        InventoryItem::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other tenant report stock',
            'available_quantity' => 0,
        ]);
        Supplier::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other tenant API supplier',
        ]);
        Activity::create([
            'log_name' => 'default',
            'description' => 'Other tenant activity',
            'subject_type' => ShopOwner::class,
            'subject_id' => $otherOwner->id,
            'event' => 'updated',
            'causer_type' => ShopOwner::class,
            'causer_id' => $otherOwner->id,
            'properties' => [],
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/customers')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonMissing(['id' => $otherCustomer->id])
            ->assertJsonMissing(['email' => $otherCustomer->email]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/dashboard-stats')
            ->assertOk()
            ->assertJsonPath('active_customers', 0)
            ->assertJsonPath('pending_reviews', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/reviews')
            ->assertOk()
            ->assertJsonPath('reviews.total', 0)
            ->assertJsonMissing(['reviewId' => 'shop_'.$otherReview->id]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/customers/'.$otherCustomer->id)
            ->assertNotFound();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/hr/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/finance/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/manager/reports')
            ->assertOk()
            ->assertJsonPath('metrics.pending_issues', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/manager/audit-logs')
            ->assertOk()
            ->assertJsonPath('logs.total', 0)
            ->assertJsonMissing(['description' => 'Other tenant activity']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.total_items', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/products')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonMissing(['name' => 'Other tenant report stock']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/inventory/movements')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/procurement/suppliers')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonMissing(['name' => 'Other tenant API supplier']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/dashboard-stats')
            ->assertOk()
            ->assertJsonPath('stats.requested', 0);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/shipments')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonMissing(['id' => $otherShipment->id]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/shipments/'.$otherShipment->id)
            ->assertForbidden();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/riders')
            ->assertOk()
            ->assertJsonPath('riders', [])
            ->assertJsonMissing(['shop_owner_id' => $otherOwner->id]);
    }
}
