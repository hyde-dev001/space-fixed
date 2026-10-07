<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\RetailWarrantyIssuance;
use App\Services\OrderReceiptService;
use App\Services\RetailWarrantyService;

class IssuanceTest extends WarrantyTestCase
{
    public function test_my_orders_batches_warranty_relations_after_existing_order_refresh(): void
    {
        $first = $this->purchase('delivered');
        $orders = [$first, $this->purchase('delivered'), $this->purchase('delivered')];
        foreach ($orders as $order) {
            $order->update(['customer_id' => $first->customer_id]);
            app(RetailWarrantyService::class)->captureFulfillment($order);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($first->customer, 'user')->get('/my-orders')->assertOk();
        $queries = array_filter(\Illuminate\Support\Facades\DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'from "retail_warrant'));
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertCount(2, $queries, 'Parent/item warranty reads should be batched for the page.');
    }

    public function test_my_orders_displays_late_payment_issuance_in_the_same_verified_reconciliation_response(): void
    {
        $order = $this->purchase('delivered');
        $order->update(['payment_status' => 'pending', 'paymongo_link_id' => 'cs_warranty_fixture']);
        $order->shopOwner->update(['paymongo_secret_key' => 'sk_test_warranty_fixture']);
        app(RetailWarrantyService::class)->captureFulfillment($order);
        \Illuminate\Support\Facades\Http::fake(['api.paymongo.com/v1/checkout_sessions/*' => \Illuminate\Support\Facades\Http::response([
            'data' => ['attributes' => ['payment_status' => 'paid', 'payments' => [['id' => 'pay_warranty_fixture', 'attributes' => ['status' => 'paid', 'source' => ['type' => 'gcash']]]]]],
        ])]);
        $this->actingAs($order->customer, 'user')->get('/my-orders')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('orders.0.product_warranty.items', 3));
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
    }

    public function test_one_order_issuance_with_three_item_coverages_only_after_actual_delivery_and_replay(): void
    {
        $order = $this->purchase();
        $this->assertNull(app(RetailWarrantyService::class)->captureFulfillment($order));
        $this->assertDatabaseCount('retail_warranty_issuances', 0);
        $this->assertDatabaseCount('retail_warranties', 0);
        app(OrderReceiptService::class)->confirm($order);
        app(OrderReceiptService::class)->confirm($order->fresh());
        app(RetailWarrantyService::class)->captureFulfillment($order->fresh());
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
        $this->assertDatabaseCount('retail_warranties', 3);
        $issuance = RetailWarrantyIssuance::firstOrFail();
        $this->assertSame([2, 1, 1], $issuance->warranties()->orderBy('order_item_id')->pluck('original_covered_quantity')->all());
        $this->assertStringStartsWith('WRNTY-', $issuance->warranty_number);
        $this->assertSame(1, $issuance->warranties->first()->policy_snapshot['duration_value']);
    }

    public function test_pre_cutover_order_and_disabled_fulfillment_are_never_retroactively_covered(): void
    {
        $service = app(RetailWarrantyService::class);
        $order = $this->purchase('delivered');
        $order->forceFill(['created_at' => now()->subDay()])->save();
        $this->assertNull($service->captureFulfillment($order));
        $this->assertArrayNotHasKey('customer', $order->fresh()->retail_warranty_policy_snapshot);
        $service->saveSettings($order->shopOwner, ['enabled' => false]);
        $other = $this->purchase('delivered');
        $service->saveSettings($other->shopOwner, ['enabled' => false]);
        $this->assertNull($service->captureFulfillment($other));
        $service->saveSettings($other->shopOwner, ['enabled' => true]);
        $this->assertNull($service->captureFulfillment($other->fresh()));
        $this->assertDatabaseCount('retail_warranty_issuances', 0);
    }

    public function test_late_payment_uses_frozen_fulfillment_policy_and_original_start(): void
    {
        $order = $this->purchase('delivered');
        $order->update(['payment_status' => 'pending']);
        $service = app(RetailWarrantyService::class);
        $this->assertNull($service->captureFulfillment($order));
        $start = $order->fresh()->retail_warranty_fulfilled_at;
        $service->saveSettings($order->shopOwner, ['enabled' => true, 'duration_value' => 5, 'duration_unit' => 'days', 'terms' => 'New policy']);
        $this->travel(2)->days();
        $order->update(['payment_status' => 'paid']);
        $issuance = $service->issueCaptured($order);
        $this->assertSame('years', $issuance->warranties->first()->policy_snapshot['duration_unit']);
        $this->assertTrue($issuance->fulfilled_at->equalTo($start));
        $this->assertSame(3, $issuance->warranties->count());
        $this->assertSame($issuance->id, $service->issueCaptured($order)->id);
    }

    public function test_unfulfilled_or_failed_orders_never_capture_or_issue(): void
    {
        $order = $this->purchase('pending');
        foreach (['pending', 'processing', 'shipped', 'cancelled'] as $status) {
            $order->update(['status' => $status]);
            $this->assertNull(app(RetailWarrantyService::class)->captureFulfillment($order));
        }
        $this->assertNull($order->fresh()->retail_warranty_fulfilled_at);
    }
}
