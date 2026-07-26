<?php

namespace Tests\Feature\Repair\Warranty;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\RepairRequest;
use App\Models\RepairWarrantyClaim;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairWarrantyClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_claim_creates_exactly_one_linked_warranty_job(): void
    {
        [$shopOwner, $repairer, $repair, $claim] = $this->seedPendingClaimContext();

        $response = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/warranty-claims/{$claim->id}/approve"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $claim->refresh();

        $this->assertSame(RepairWarrantyClaim::STATUS_APPROVED, (string) $claim->status);
        $this->assertNotNull($claim->approved_repair_request_id);

        $linked = RepairRequest::query()->findOrFail((int) $claim->approved_repair_request_id);
        $this->assertTrue((bool) $linked->is_warranty_job);
        $this->assertSame((int) $repair->id, (int) $linked->parent_repair_request_id);
        $this->assertSame('warranty_no_charge', (string) $linked->billing_mode);
        $this->assertSame(0.0, (float) $linked->final_total);
        $this->assertFalse((bool) $linked->payment_enabled);

        $this->assertSame(
            1,
            RepairRequest::query()
                ->where('parent_repair_request_id', $repair->id)
                ->where('is_warranty_job', true)
                ->count()
        );

        $secondAttempt = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/warranty-claims/{$claim->id}/approve"
        );

        $secondAttempt->assertStatus(422);

        $this->assertSame(
            1,
            RepairRequest::query()
                ->where('parent_repair_request_id', $repair->id)
                ->where('is_warranty_job', true)
                ->count()
        );
    }

    public function test_approve_claim_does_not_reduce_existing_recognized_revenue(): void
    {
        [$shopOwner, $repairer, $repair, $claim] = $this->seedPendingClaimContext();

        $repair->forceFill([
            'total' => 1120.00,
            'final_total' => 1120.00,
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'total_paid_amount' => 1120.00,
            'total_refunded_amount' => 0.00,
            'payment_status' => 'completed',
        ])->save();

        $beforeRevenue = $this->dashboardStyleRepairRevenueForShop((int) $shopOwner->id);

        $response = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/warranty-claims/{$claim->id}/approve"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $afterRevenue = $this->dashboardStyleRepairRevenueForShop((int) $shopOwner->id);

        $this->assertEqualsWithDelta($beforeRevenue, $afterRevenue, 0.0001);

        $repair->refresh();
        $this->assertSame(1120.0, (float) $repair->total_paid_amount);
        $this->assertSame(0.0, (float) $repair->total_refunded_amount);
    }

    public function test_approve_claim_preserves_shop_owned_quotes_as_shop_sponsored_delivery(): void
    {
        [, , $address, $linked, $delivery] = $this->approveShopSponsoredWarranty();

        $this->assertSame('pickup', (string) $linked->delivery_method);
        $this->assertSame('shop_pickup', (string) $linked->intake_delivery_method);
        $this->assertSame('shop_delivery', (string) $linked->return_delivery_method);
        $this->assertSame($address->id, data_get($linked->intake_address, 'address_id'));
        $this->assertSame($address->id, data_get($linked->return_address, 'address_id'));
        $this->assertSame(
            $delivery->snapshot($address, 'shop_pickup')['version'],
            data_get($linked->intake_address, 'version')
        );
        $this->assertSame(
            $delivery->snapshot($address, 'shop_delivery')['version'],
            data_get($linked->return_address, 'version')
        );
        $this->assertGreaterThan(0, (float) $linked->intake_delivery_fee);
        $this->assertGreaterThan(0, (float) $linked->return_delivery_fee);
        $this->assertTrue((bool) data_get($linked->intake_logistics_quote, 'available'));
        $this->assertTrue((bool) data_get($linked->return_logistics_quote, 'available'));
        $this->assertFalse((bool) $linked->payment_enabled);
        $this->assertNull($linked->payment_enabled_at);
        $this->assertSame('completed', (string) $linked->payment_status);
        $this->assertSame('completed', (string) $linked->payment_status_derived);
        $this->assertSame(0.0, (float) $linked->total_paid_amount);
        $this->assertSame(0.0, (float) $linked->final_total);
        $this->assertNotNull($linked->intake_logistics_locked_at);
        $this->assertNotNull($linked->return_logistics_locked_at);
        $this->assertSame(
            data_get($linked->return_address, 'version'),
            (string) $linked->return_address_confirmed_version,
        );
        $this->assertSame(
            0,
            Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $linked->id)
                ->count()
        );

        $linked->update(['status' => 'repairer_accepted']);
        $delivery->tryCreateIntakeShipment($linked->fresh());
        $delivery->tryCreateIntakeShipment($linked->fresh());

        $this->assertSame(1, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_pickup')
            ->count());

        $linked->update(['status' => 'ready_for_pickup']);
        $delivery->tryCreateReturnShipment($linked->fresh());
        $delivery->tryCreateReturnShipment($linked->fresh());

        $this->assertSame(1, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_return')
            ->count());
    }

    public function test_shop_sponsored_warranty_intake_coverage_loss_unlocks_customer_fallback(): void
    {
        [$settings, , $address, $linked, $delivery] = $this->approveShopSponsoredWarranty();
        $customer = User::query()->findOrFail($linked->user_id);
        $returnLock = $linked->return_logistics_locked_at->copy();
        $linked->update(['status' => 'repairer_accepted']);
        $settings->update(['coverage_radius_km' => 0.01]);

        $this->assertNull($delivery->tryCreateIntakeShipment($linked->fresh()));
        $this->assertNull($delivery->tryCreateIntakeShipment($linked->fresh()));
        $this->assertSame(0, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_pickup')
            ->count());
        $failed = $linked->fresh();
        $this->assertNull($failed->intake_logistics_locked_at);
        $this->assertTrue($failed->return_logistics_locked_at->equalTo($returnLock));
        $this->assertSame('shop_pickup', (string) $failed->intake_delivery_method);
        $this->assertSame('repairer_accepted', (string) $failed->status);
        $this->assertNull($failed->logistics_payment_reconciliation);
        $this->assertSame(0.0, (float) $failed->total_refunded_amount);
        $this->assertDatabaseCount('pos_refunds', 0);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$linked->id}/delivery-method", [
                'intake_delivery_method' => 'customer_delivery',
                'intake_address_id' => $address->id,
            ])
            ->assertOk();

        $fallback = $linked->fresh();
        $this->assertSame('customer_delivery', (string) $fallback->intake_delivery_method);
        $this->assertSame(0.0, (float) $fallback->intake_delivery_fee);
        $this->assertNotNull($fallback->intake_logistics_locked_at);
        $this->assertTrue($fallback->return_logistics_locked_at->equalTo($returnLock));
    }

    public function test_shop_sponsored_warranty_return_coverage_loss_unlocks_customer_fallback(): void
    {
        [$settings, , $address, $linked, $delivery] = $this->approveShopSponsoredWarranty();
        $customer = User::query()->findOrFail($linked->user_id);
        $intakeLock = $linked->intake_logistics_locked_at->copy();
        $linked->update(['status' => 'ready_for_pickup']);
        $settings->update(['coverage_radius_km' => 0.01]);

        $this->assertNull($delivery->tryCreateReturnShipment($linked->fresh()));
        $this->assertNull($delivery->tryCreateReturnShipment($linked->fresh()));
        $this->assertSame(0, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_return')
            ->count());
        $failed = $linked->fresh();
        $this->assertNull($failed->return_logistics_locked_at);
        $this->assertNull($failed->return_address_confirmed_at);
        $this->assertNull($failed->return_address_confirmed_version);
        $this->assertTrue($failed->intake_logistics_locked_at->equalTo($intakeLock));
        $this->assertSame('shop_delivery', (string) $failed->return_delivery_method);
        $this->assertSame('ready_for_pickup', (string) $failed->status);
        $this->assertNull($failed->logistics_payment_reconciliation);
        $this->assertSame(0.0, (float) $failed->total_refunded_amount);
        $this->assertDatabaseCount('pos_refunds', 0);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$linked->id}/delivery-method", [
                'return_delivery_method' => 'customer_pickup',
                'return_address_id' => $address->id,
                'same_as_intake_address' => false,
            ])
            ->assertOk();

        $fallback = $linked->fresh();
        $this->assertSame('customer_pickup', (string) $fallback->return_delivery_method);
        $this->assertSame(0.0, (float) $fallback->return_delivery_fee);
        $this->assertNotNull($fallback->return_logistics_locked_at);
        $this->assertNull($fallback->return_address_confirmed_at);
        $this->assertNull($fallback->return_address_confirmed_version);
        $this->assertTrue($fallback->intake_logistics_locked_at->equalTo($intakeLock));
    }

    public function test_duplicate_shop_sponsored_warranty_intake_cancellation_without_shipment_is_idempotent(): void
    {
        [, $repairer, , $linked] = $this->approveShopSponsoredWarranty();
        $linked->update(['status' => 'repairer_accepted']);

        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$linked->id}/cancel-delivery-leg",
            ['leg' => 'intake', 'reason' => 'Cancel before rider planning.'],
        )->assertOk();

        $this->assertNull($linked->fresh()->intake_logistics_locked_at);
        $this->assertSame(0, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_pickup')
            ->count());

        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$linked->id}/cancel-delivery-leg",
            ['leg' => 'intake', 'reason' => 'Repeated cancellation request.'],
        )
            ->assertOk()
            ->assertJsonPath('data.reconciliation', null);

        $this->assertNull($linked->fresh()->logistics_payment_reconciliation);
        $this->assertSame(0.0, (float) $linked->fresh()->total_refunded_amount);
        $this->assertDatabaseCount('pos_refunds', 0);
    }

    public function test_cancelled_shop_sponsored_warranty_intake_can_be_replanned_and_retried_without_refund(): void
    {
        [, $repairer, $address, $linked, $delivery] = $this->approveShopSponsoredWarranty();
        $customer = User::query()->findOrFail($linked->user_id);
        $linked->update(['status' => 'repairer_accepted']);
        $shipment = $delivery->tryCreateIntakeShipment($linked->fresh());
        $this->assertNotNull($shipment);

        $cancel = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$linked->id}/cancel-delivery-leg",
            ['leg' => 'intake', 'reason' => 'Customer requested a different pickup address.'],
        );

        $cancel->assertOk();
        $this->assertSame('cancelled', $shipment?->fresh()->status->value);
        $this->assertNull($linked->fresh()->logistics_payment_reconciliation);
        $firstCancelledAt = $shipment->fresh()->cancelled_at;

        $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$linked->id}/cancel-delivery-leg",
            ['leg' => 'intake', 'reason' => 'Repeated cancellation request.'],
        )
            ->assertOk()
            ->assertJsonPath('data.reconciliation', null);

        $this->assertTrue($shipment->fresh()->cancelled_at->equalTo($firstCancelledAt));
        $this->assertSame(1, $shipment->fresh()->legs()->count());
        $cancelledAt = $shipment->fresh()->cancelled_at->copy()->startOfSecond();
        $shipment->update(['cancelled_at' => $cancelledAt]);
        $linked->refresh()->update(['intake_logistics_locked_at' => $cancelledAt]);
        $this->assertTrue($linked->fresh()->intake_logistics_locked_at->equalTo($shipment->fresh()->cancelled_at));
        $this->assertNull($delivery->tryCreateIntakeShipment($linked->fresh()));
        $this->assertSame(1, $shipment->fresh()->legs()->count());
        $linked->update(['intake_logistics_locked_at' => null]);
        $this->assertNull($linked->fresh()->intake_logistics_locked_at);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$linked->id}/delivery-method", [
                'intake_delivery_method' => 'shop_pickup',
                'intake_address_id' => $address->id,
            ])
            ->assertOk();

        $replanned = $linked->fresh();
        $this->assertFalse((bool) $replanned->payment_enabled);
        $this->assertSame('completed', (string) $replanned->payment_status);
        $this->assertNotNull($replanned->intake_logistics_locked_at);
        $this->assertTrue(
            $replanned->intake_logistics_locked_at->greaterThan($cancelledAt),
            "Replan lock {$replanned->intake_logistics_locked_at} must be later than cancellation {$cancelledAt}.",
        );
        $this->assertGreaterThan(0, (float) $replanned->intake_delivery_fee);
        $this->assertTrue((bool) data_get($replanned->intake_logistics_quote, 'available'));

        $replacement = $delivery->tryCreateIntakeShipment($replanned);
        $delivery->tryCreateIntakeShipment($linked->fresh());

        $this->assertSame($shipment->id, $replacement?->id);
        $this->assertSame(1, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_pickup')
            ->count());
        $this->assertSame(2, $shipment->fresh()->legs()->count());
        $this->assertSame(1, $shipment->fresh()->legs()->where('status', '!=', 'cancelled')->count());
        $this->assertNull($linked->fresh()->logistics_payment_reconciliation);
        $this->assertSame(0.0, (float) $linked->fresh()->total_refunded_amount);
        $this->assertDatabaseCount('pos_refunds', 0);
        $this->assertStringNotContainsString('Finance', (string) $cancel->json('message'));
    }

    public function test_cancelled_shop_sponsored_warranty_return_can_be_replanned_and_retried_without_refund(): void
    {
        [, $repairer, $address, $linked, $delivery] = $this->approveShopSponsoredWarranty();
        $customer = User::query()->findOrFail($linked->user_id);
        $linked->update(['status' => 'ready_for_pickup']);
        $shipment = $delivery->tryCreateReturnShipment($linked->fresh());
        $this->assertNotNull($shipment);

        $cancel = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$linked->id}/cancel-delivery-leg",
            ['leg' => 'return', 'reason' => 'Customer requested a different return address.'],
        );

        $cancel->assertOk();
        $this->assertSame('cancelled', $shipment?->fresh()->status->value);
        $this->assertSame('ready_for_pickup', (string) $linked->fresh()->status);
        $this->assertNull($linked->fresh()->logistics_payment_reconciliation);
        $cancelledAt = $shipment->fresh()->cancelled_at->copy()->startOfSecond();
        $shipment->update(['cancelled_at' => $cancelledAt]);
        $linked->refresh()->update([
            'return_logistics_locked_at' => $cancelledAt,
            'return_address_confirmed_at' => $cancelledAt,
            'return_address_confirmed_version' => data_get($linked->return_address, 'version'),
        ]);
        $this->assertTrue($linked->fresh()->return_logistics_locked_at->equalTo($shipment->fresh()->cancelled_at));
        $this->assertNull($delivery->tryCreateReturnShipment($linked->fresh()));
        $this->assertSame(1, $shipment->fresh()->legs()->count());
        $linked->update([
            'return_logistics_locked_at' => null,
            'return_address_confirmed_at' => null,
            'return_address_confirmed_version' => null,
        ]);
        $this->assertNull($linked->fresh()->return_logistics_locked_at);

        $this->actingAs($customer, 'user')
            ->patchJson("/api/customer/repairs/{$linked->id}/delivery-method", [
                'return_delivery_method' => 'shop_delivery',
                'return_address_id' => $address->id,
            ])
            ->assertOk();

        $replanned = $linked->fresh();
        $this->assertFalse((bool) $replanned->payment_enabled);
        $this->assertSame('completed', (string) $replanned->payment_status);
        $this->assertNotNull($replanned->return_logistics_locked_at);
        $this->assertTrue(
            $replanned->return_logistics_locked_at->greaterThan($cancelledAt),
            "Replan lock {$replanned->return_logistics_locked_at} must be later than cancellation {$cancelledAt}.",
        );
        $this->assertSame(
            data_get($replanned->return_address, 'version'),
            (string) $replanned->return_address_confirmed_version,
        );
        $this->assertGreaterThan(0, (float) $replanned->return_delivery_fee);
        $this->assertTrue((bool) data_get($replanned->return_logistics_quote, 'available'));

        $replacement = $delivery->tryCreateReturnShipment($replanned);
        $delivery->tryCreateReturnShipment($linked->fresh());

        $this->assertSame($shipment->id, $replacement?->id);
        $this->assertSame(1, Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $linked->id)
            ->where('purpose', 'repair_return')
            ->count());
        $this->assertSame(2, $shipment->fresh()->legs()->count());
        $this->assertSame(1, $shipment->fresh()->legs()->where('status', '!=', 'cancelled')->count());
        $this->assertNull($linked->fresh()->logistics_payment_reconciliation);
        $this->assertSame(0.0, (float) $linked->fresh()->total_refunded_amount);
        $this->assertDatabaseCount('pos_refunds', 0);
        $this->assertStringNotContainsString('Finance', (string) $cancel->json('message'));
    }

    public function test_outside_coverage_blocks_shop_owned_warranty_delivery_but_allows_third_party(): void
    {
        [$shop, $repairer, $repair, $claim] = $this->seedPendingClaimContext();
        $shop->update([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::query()->create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 2,
        ]);
        $address = $this->addressFor(
            User::query()->findOrFail($repair->user_id),
            ['latitude' => 15.2, 'longitude' => 121.7],
        );
        $delivery = app(RepairDeliveryService::class);
        $repair->forceFill([
            'intake_address' => $delivery->snapshot($address, 'customer_delivery'),
            'pickup_address' => $delivery->snapshot($address, 'customer_delivery'),
        ])->save();
        $claim->forceFill(['preferred_return_method' => 'shop_pickup'])->save();

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/warranty-claims/{$claim->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('intake_address');

        $this->assertNull($claim->fresh()->approved_repair_request_id);

        $claim->forceFill(['preferred_return_method' => 'customer_delivery'])->save();

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/warranty-claims/{$claim->id}/approve")
            ->assertOk();

        $linked = RepairRequest::query()->findOrFail((int) $claim->fresh()->approved_repair_request_id);
        $this->assertSame('customer_delivery', (string) $linked->intake_delivery_method);
        $this->assertSame(0.0, (float) $linked->intake_delivery_fee);
        $this->assertSame('completed', (string) $linked->payment_status);
        $this->assertFalse((bool) $linked->payment_enabled);
        $this->assertSame(
            0,
            Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $linked->id)
                ->count()
        );
    }

    public function test_reject_claim_persists_reason_and_creates_no_linked_job(): void
    {
        [, $repairer, $repair, $claim] = $this->seedPendingClaimContext();

        $response = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/warranty-claims/{$claim->id}/reject",
            ['rejection_reason' => 'Issue does not match the original repair scope.']
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', RepairWarrantyClaim::STATUS_REJECTED);

        $claim->refresh();
        $this->assertSame(RepairWarrantyClaim::STATUS_REJECTED, (string) $claim->status);
        $this->assertSame('Issue does not match the original repair scope.', (string) $claim->rejection_reason);
        $this->assertNull($claim->approved_repair_request_id);

        $this->assertSame(
            0,
            RepairRequest::query()
                ->where('parent_repair_request_id', $repair->id)
                ->where('is_warranty_job', true)
                ->count()
        );
    }

    public function test_repairer_kpi_endpoint_returns_scoped_warranty_metrics(): void
    {
        [$shopOwner, $repairer, $repair, $claim] = $this->seedPendingClaimContext();

        $otherRepairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $otherRepair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => User::factory()->create()->id,
            'assigned_repairer_id' => $otherRepairer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(2),
            'received_at' => now()->subDays(2),
            'payment_status' => 'completed',
        ]);

        RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-FLOW-0002',
            'original_repair_request_id' => $otherRepair->id,
            'customer_user_id' => $otherRepair->user_id,
            'shop_owner_id' => $shopOwner->id,
            'repair_handler_user_id' => $otherRepairer->id,
            'handler_source' => 'business_employee',
            'status' => RepairWarrantyClaim::STATUS_APPROVED,
            'reason_code' => 'issue_returned',
            'reason_details' => 'Approved claim for another repairer.',
            'same_issue_confirmation' => true,
            'evidence_media' => ['repair-warranty-claims/other-proof.jpg'],
            'preferred_return_method' => 'walk_in',
            'shipping_cost_bearer' => 'customer',
            'source_channel' => 'manual_pos_walk_in',
            'warranty_started_at_snapshot' => now()->subDays(2),
            'warranty_expires_at_snapshot' => now()->addDays(20),
            'reviewed_by_repairer_id' => $otherRepairer->id,
            'reviewed_at' => now()->subHours(6),
        ]);

        $response = $this->actingAs($repairer, 'user')->getJson('/api/repairer/warranty-claims/kpi?days=30');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.window_days', 30)
            ->assertJsonPath('data.total_claims', 1)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.approved_count', 0)
            ->assertJsonPath('data.from_pos_count', 0)
            ->assertJsonPath('data.from_customer_portal_count', 1);

        $claim->refresh();
        $repair->refresh();
    }

    public function test_repairer_index_without_status_filter_returns_all_claim_statuses(): void
    {
        [$shopOwner, $repairer, $repair, $pendingClaim] = $this->seedPendingClaimContext();

        $approvedRepair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => User::factory()->create()->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(1),
            'received_at' => now()->subDays(2),
            'payment_status' => 'completed',
        ]);

        $approvedClaim = RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-FLOW-0003',
            'original_repair_request_id' => $approvedRepair->id,
            'customer_user_id' => $approvedRepair->user_id,
            'shop_owner_id' => $shopOwner->id,
            'repair_handler_user_id' => $repairer->id,
            'handler_source' => 'business_employee',
            'status' => RepairWarrantyClaim::STATUS_APPROVED,
            'reason_code' => 'issue_returned',
            'reason_details' => 'Approved claim used for status aggregation test.',
            'same_issue_confirmation' => true,
            'evidence_media' => ['repair-warranty-claims/all-status-proof.jpg'],
            'preferred_return_method' => 'walk_in',
            'shipping_cost_bearer' => 'customer',
            'source_channel' => 'manual_pos_walk_in',
            'warranty_started_at_snapshot' => now()->subDays(1),
            'warranty_expires_at_snapshot' => now()->addDays(20),
            'reviewed_by_repairer_id' => $repairer->id,
            'reviewed_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($repairer, 'user')->getJson('/api/repairer/warranty-claims');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $returnedStatuses = collect(data_get($response->json(), 'data', []))
            ->pluck('status')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            RepairWarrantyClaim::STATUS_APPROVED,
            RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
        ], $returnedStatuses);

        $filteredResponse = $this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/warranty-claims?status=' . RepairWarrantyClaim::STATUS_PENDING_REPAIRER);

        $filteredResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $pendingClaim->id)
            ->assertJsonPath('data.0.status', RepairWarrantyClaim::STATUS_PENDING_REPAIRER);

        $repair->refresh();
        $pendingClaim->refresh();
        $approvedClaim->refresh();
    }

    public function test_assigned_repairer_can_reject_claim_when_handler_is_unset(): void
    {
        [, $repairer, $repair, $claim] = $this->seedPendingClaimContext();

        $claim->forceFill([
            'repair_handler_user_id' => null,
            'handler_source' => null,
        ])->save();

        $response = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/warranty-claims/{$claim->id}/reject",
            ['rejection_reason' => 'Handled by currently assigned repairer.']
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', RepairWarrantyClaim::STATUS_REJECTED);

        $claim->refresh();
        $repair->refresh();

        $this->assertSame(RepairWarrantyClaim::STATUS_REJECTED, (string) $claim->status);
    }

    private function dashboardStyleRepairRevenueForShop(int $shopOwnerId): float
    {
        $vatDivisor = 1.12;

        $expression = "
            CASE
                WHEN (COALESCE(total_paid_amount, 0) > 0 OR COALESCE(total_refunded_amount, 0) > 0)
                    THEN CASE
                        WHEN (COALESCE(total_paid_amount, 0) - COALESCE(total_refunded_amount, 0)) < 0 THEN 0
                        ELSE ((COALESCE(total_paid_amount, 0) - COALESCE(total_refunded_amount, 0)) / {$vatDivisor})
                    END
                WHEN payment_status = 'completed'
                    THEN (COALESCE(final_total, total, 0) / {$vatDivisor})
                WHEN payment_status = 'paid'
                    THEN CASE
                        WHEN COALESCE(payment_policy_snapshot, payment_policy, 'deposit_50') = 'deposit_50'
                            THEN ((COALESCE(final_total, total, 0) * 0.5) / {$vatDivisor})
                        ELSE (COALESCE(final_total, total, 0) / {$vatDivisor})
                    END
                ELSE 0
            END
        ";

        return (float) RepairRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($query) {
                $query->whereNull('is_warranty_job')
                    ->orWhere('is_warranty_job', false);
            })
            ->sum(DB::raw($expression));
    }

    /**
     * @return array{0: ShopOwner, 1: User, 2: RepairRequest, 3: RepairWarrantyClaim}
     */
    private function seedPendingClaimContext(): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 30,
        ]);

        $customer = User::factory()->create();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(2),
            'received_at' => now()->subDays(3),
            'payment_status' => 'completed',
        ]);

        $claim = RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-FLOW-0001',
            'original_repair_request_id' => $repair->id,
            'customer_user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'repair_handler_user_id' => $repairer->id,
            'handler_source' => 'business_employee',
            'status' => RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
            'reason_code' => 'issue_returned',
            'reason_details' => 'Issue returned shortly after pickup.',
            'same_issue_confirmation' => true,
            'evidence_media' => ['repair-warranty-claims/proof.jpg'],
            'preferred_return_method' => 'walk_in',
            'shipping_cost_bearer' => 'customer',
            'source_channel' => 'customer_portal',
            'warranty_started_at_snapshot' => now()->subDays(2),
            'warranty_expires_at_snapshot' => now()->addDays(20),
        ]);

        return [$shopOwner, $repairer, $repair, $claim];
    }

    /**
     * @return array{0: LogisticsSetting, 1: User, 2: UserAddress, 3: RepairRequest, 4: RepairDeliveryService}
     */
    private function approveShopSponsoredWarranty(): array
    {
        [$shop, $repairer, $repair, $claim] = $this->seedPendingClaimContext();
        $shop->update([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $settings = LogisticsSetting::query()->create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 12,
        ]);
        $address = $this->addressFor(User::query()->findOrFail($repair->user_id));
        $delivery = app(RepairDeliveryService::class);

        $repair->forceFill([
            'intake_address' => $delivery->snapshot($address, 'customer_delivery'),
            'pickup_address' => $delivery->snapshot($address, 'customer_delivery'),
            'return_address' => $delivery->snapshot($address, 'customer_pickup'),
        ])->save();
        $claim->forceFill([
            'preferred_return_method' => 'shop_pickup',
            'preferred_receive_method' => 'shop_delivery',
        ])->save();

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/warranty-claims/{$claim->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true);

        return [
            $settings,
            $repairer,
            $address,
            RepairRequest::query()->findOrFail((int) $claim->fresh()->approved_repair_request_id),
            $delivery,
        ];
    }

    private function addressFor(User $customer, array $overrides = []): UserAddress
    {
        return UserAddress::query()->create(array_merge([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => '09171234567',
            'region' => 'CALABARZON',
            'province' => 'Cavite',
            'city' => 'General Trias City',
            'barangay' => 'Buenavista II',
            'postal_code' => '4107',
            'address_line' => '126 Ilang-ilang Street',
            'latitude' => 14.6000,
            'longitude' => 120.9800,
            'delivery_instructions' => 'Blue gate',
        ], $overrides));
    }
}
