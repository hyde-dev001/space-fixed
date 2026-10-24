<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\Product;
use App\Models\RetailWarranty;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RetailPosPaymentService;
use App\Services\RetailWarrantyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_units_month_end_leap_year_and_timezone_keep_exact_instants(): void
    {
        $service = app(RetailWarrantyService::class);
        foreach ([['2026-01-31 23:00:00', 'months', '2026-02-28 23:00:00'], ['2024-02-29 23:00:00', 'years', '2025-02-28 23:00:00'],
            ['2026-10-07 00:00:00', 'days', '2026-10-08 00:00:00'], ['2026-10-07 00:00:00', 'weeks', '2026-10-14 00:00:00']] as [$start, $unit, $expected]) {
            $instant = CarbonImmutable::parse($start, 'Asia/Manila');
            $this->assertTrue($service->expiration($instant->utc(), 1, $unit, 'Asia/Manila')->equalTo(CarbonImmutable::parse($expected, 'Asia/Manila')));
        }
    }

    public function test_registered_pos_uses_linked_buyer_identity_not_arbitrary_walk_in_contact_and_replays_once(): void
    {
        Queue::fake();
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'both']);
        app(RetailWarrantyService::class)->saveSettings($shop, ['enabled' => true, 'title' => 'Product Warranty', 'duration_value' => 1, 'duration_unit' => 'years', 'terms' => 'Original terms']);
        $buyer = User::factory()->create(['name' => 'Registered Buyer', 'email' => 'linked@example.test']);
        $product = Product::create(['shop_owner_id' => $shop->id, 'name' => 'Shoe', 'slug' => 'pos-shoe', 'price' => 100, 'stock_quantity' => 10, 'is_active' => true]);
        $payload = ['idempotency_key' => 'warranty-pos-repeat', 'customer_type' => 'registered', 'customer_id' => $buyer->id,
            'walk_in_name' => 'Wrong Name', 'walk_in_email' => 'wrong@example.test',
            'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100]], 'payment_lines' => [['tender_type' => 'cash', 'amount' => 200]]];
        $cashier = User::factory()->create(['shop_owner_id' => $shop->id]);
        $pos = app(RetailPosPaymentService::class)->checkout($shop->id, $payload, $cashier->id);
        app(RetailPosPaymentService::class)->checkout($shop->id, $payload, $cashier->id);
        $issuance = $pos->sourceOrder->retailWarrantyIssuance;
        $this->assertSame($buyer->id, $issuance->customer_id);
        $this->assertSame('linked@example.test', $issuance->customer_snapshot['email']);
        $this->assertSame('Registered Buyer', $issuance->customer_snapshot['name']);
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
        $this->assertDatabaseCount('retail_warranties', 1);
        $this->assertSame(2, $issuance->warranties->first()->original_covered_quantity);
    }

    public function test_item_snapshot_cannot_be_edited_after_issuance(): void
    {
        $warranty = RetailWarranty::factory()->create();
        $this->expectException(\LogicException::class);
        $warranty->update(['policy_snapshot' => ['terms' => 'Rewritten']]);
    }

    public function test_walk_in_pos_email_never_links_an_account_by_email_matching(): void
    {
        Queue::fake();
        $buyer = User::factory()->create(['email' => 'walkin@example.test']);
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        app(RetailWarrantyService::class)->saveSettings($shop, ['enabled' => true, 'title' => 'Product Warranty', 'duration_value' => 5, 'duration_unit' => 'days', 'terms' => 'Shop terms']);
        $product = Product::create(['shop_owner_id' => $shop->id, 'name' => 'Shoe', 'slug' => 'walkin-shoe', 'price' => 100, 'stock_quantity' => 10, 'is_active' => true]);
        $cashier = User::factory()->create(['shop_owner_id' => $shop->id]);
        foreach (['walkin@example.test', null] as $index => $email) {
            $pos = app(RetailPosPaymentService::class)->checkout($shop->id, ['idempotency_key' => 'walkin-warranty-'.$index, 'customer_type' => 'walk_in', 'walk_in_name' => 'Walk-in Buyer', 'walk_in_email' => $email,
                'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]], 'payment_lines' => [['tender_type' => 'cash', 'amount' => 100]]], $cashier->id);
            $issuance = $pos->sourceOrder->retailWarrantyIssuance;
            $this->assertNull($issuance->customer_id);
            $this->assertSame($email, $issuance->customer_snapshot['email']);
            $this->actingAs($buyer, 'user')->getJson('/orders/warranties/'.$issuance->warranty_number)->assertNotFound();
        }
    }

    public function test_stored_expiry_is_not_automatically_reactivated_by_a_clock_change(): void
    {
        $warranty = RetailWarranty::factory()->create();
        $warranty->forceFill(['status' => 'expired'])->save();
        $projection = app(RetailWarrantyService::class)->projectIssuances(new \Illuminate\Database\Eloquent\Collection([$warranty->issuance]));
        $this->assertSame('expired', $projection[$warranty->order_id]['items'][0]['status']);
        $this->assertFalse($projection[$warranty->order_id]['items'][0]['can_assess']);
    }
}
