<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\OrderRefund;
use App\Services\RetailWarrantyService;
use Illuminate\Support\Facades\DB;

class ConsumptionTest extends WarrantyTestCase
{
    public function test_scheduler_recovers_captured_late_payment_using_original_policy_and_sends_once(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $order = $this->purchase('delivered');
        $order->update(['payment_status' => 'pending']);
        $service = app(RetailWarrantyService::class);
        $this->assertNull($service->captureFulfillment($order));
        $service->saveSettings($order->shopOwner, ['enabled' => true, 'duration_value' => 5, 'duration_unit' => 'days']);
        // Simulate confirmed paid facts whose normal issuance callback was lost.
        $order->update(['payment_status' => 'paid']);
        $this->artisan('retail-warranties:reconcile')->assertExitCode(0);
        $issuance = $order->fresh()->retailWarrantyIssuance;
        $this->assertNotNull($issuance);
        $this->assertSame('years', $issuance->warranties->first()->policy_snapshot['duration_unit']);
        (new \App\Jobs\DeliverRetailWarranty($issuance->id))->handle(app(\App\Services\RetailWarrantyCertificateService::class));
        $this->artisan('retail-warranties:reconcile')->assertExitCode(0);
        (new \App\Jobs\DeliverRetailWarranty($issuance->id))->handle(app(\App\Services\RetailWarrantyCertificateService::class));
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
        $this->assertCount(1, \Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_only_success_consumes_selected_quantity_and_reconciliation_replay_is_idempotent(): void
    {
        $order = $this->purchase('delivered');
        $service = app(RetailWarrantyService::class);
        $issuance = $service->captureFulfillment($order);
        $warranty = $issuance->warranties->first();
        $refund = OrderRefund::factory()->create(['order_id' => $order->id, 'shop_owner_id' => $order->shop_owner_id, 'customer_id' => $order->customer_id, 'amount' => 250]);
        $refund->items()->create(['order_item_id' => $warranty->order_item_id, 'product_id' => $warranty->orderItem->product_id,
            'retail_warranty_id' => $warranty->id, 'requested_qty' => 1, 'approved_qty' => 1, 'unit_price_snapshot' => 250, 'line_amount' => 250, 'inspection_disposition' => 'damaged']);
        foreach (['requested', 'pending_approval', 'approved', 'processing', 'failed', 'rejected', 'cancelled'] as $status) {
            $refund->update(['status' => $status]);
            $service->reconcileOrder($order);
            $this->assertSame(0, $warranty->fresh()->refunded_quantity);
            $this->assertSame('active', $warranty->fresh()->status);
            if (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
                $this->assertSame(0, $service->quantities($issuance->warranties)[$warranty->id]['reserved']);
            }
        }
        $refund->update(['status' => 'succeeded']);
        $service->reconcileOrder($order);
        $service->reconcileOrder($order);
        $this->assertSame(1, $warranty->fresh()->refunded_quantity);
        $this->assertSame('active', $warranty->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'retail_warranty.quantity_consumed')->where('target_id', $warranty->id)->count());
        $this->assertSame([1, 1, 1], array_column($service->quantities($issuance->fresh()->warranties), 'remaining'));
        $refund->items()->first()->update(['approved_qty' => 2]);
        $service->reconcileOrder($order);
        $this->assertSame('voided', $warranty->fresh()->status);
        $this->assertSame(0, $service->quantities($issuance->fresh()->warranties)[$warranty->id]['available']);
    }

    public function test_scheduler_recovers_pending_delivery_and_expires_without_resending_unknown_mail(): void
    {
        $order = $this->purchase('delivered');
        $issuance = app(RetailWarrantyService::class)->captureFulfillment($order);
        $issuance->forceFill(['email_delivery_state' => 'sending', 'email_attempted_at' => now()->subMinutes(20)])->save();
        $this->travel(2)->years();
        $this->artisan('retail-warranties:reconcile')->assertExitCode(0);
        $this->assertSame('unknown', $issuance->fresh()->email_delivery_state);
        $this->assertSame(['expired'], $issuance->warranties()->pluck('status')->unique()->all());
        $this->artisan('retail-warranties:reconcile')->assertExitCode(0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'retail_warranty.email_unknown')->count());
    }
}
