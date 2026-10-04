<?php

namespace Tests\Feature\Repair\Warranty;

use App\Models\PosRefund;
use App\Models\PosRefundLeg;
use App\Models\PosPaymentLine;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\RepairWarrantyClaim;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RepairPosRefundService;
use App\Services\RepairWarrantyService;
use App\Services\RepairOnlineRefundWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepairResolutionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('blockingServiceRefunds')]
    public function test_service_refund_blocks_warranty_in_validator_and_customer_payload(string $status, float $amount): void
    {
        [$repair, $customer, $source] = $this->context();
        $this->refund($repair, $source, $status, $amount);

        $this->assertFalse(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
        $this->actingAs($customer, 'user')->getJson('/api/customer/repairs')
            ->assertOk()->assertJsonPath('data.0.warranty.can_claim', false);
    }

    public static function blockingServiceRefunds(): array
    {
        return [
            'requested' => ['requested', 100],
            'approved' => ['approved', 100],
            'processing' => ['processing', 100],
            'full service refund' => ['succeeded', 1000],
            'partial service coverage cannot be represented' => ['succeeded', 100],
        ];
    }

    public function test_delivery_only_compensation_preserves_service_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $this->refund($repair, $source, 'succeeded', 100, 'delivery_reconciliation');

        $this->assertTrue(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
        $this->actingAs($customer, 'user')->getJson('/api/customer/repairs')
            ->assertOk()->assertJsonPath('data.0.warranty.can_claim', true);
    }

    public function test_rejected_refund_does_not_remove_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $this->refund($repair, $source, 'rejected', 100);
        $this->assertTrue(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
    }

    public function test_successful_service_leg_blocks_warranty_even_if_other_leg_failed(): void
    {
        [$repair, $customer, $source] = $this->context();
        $refund = $this->refund($repair, $source, 'failed', 100);
        PosRefundLeg::query()->create([
            'pos_refund_id' => $refund->id, 'leg_type' => 'gateway',
            'requested_amount' => 50, 'approved_amount' => 50, 'status' => 'succeeded',
        ]);
        $this->assertFalse(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
    }

    public function test_recorded_service_refund_blocks_warranty_without_a_refund_row(): void
    {
        [$repair, $customer] = $this->context();
        $repair->update(['payment_status' => 'partially_refunded', 'total_refunded_amount' => 100]);
        $this->assertFalse(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
    }

    public function test_failed_refund_without_payout_preserves_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $this->refund($repair, $source, 'failed', 100);
        $this->assertTrue(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
    }

    public function test_new_service_refund_is_blocked_across_different_payment_sources(): void
    {
        [$repair, $customer, $source] = $this->context();
        $this->refund($repair, $source, 'requested', 100);
        $second = $source->replicate();
        $second->transaction_no = 'POS-SECOND-'.$repair->id;
        $second->save();
        $this->expectException(ValidationException::class);
        app(RepairPosRefundService::class)->requestRefund($second, $this->refundPayload(), $customer->id);
    }

    public function test_approved_warranty_rework_is_active_until_customer_handover(): void
    {
        [$repair, $customer, $source] = $this->context();
        $job = RepairRequest::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id, 'user_id' => $customer->id,
            'parent_repair_request_id' => $repair->id, 'is_warranty_job' => true,
            'billing_mode' => 'warranty_no_charge', 'status' => 'ready_for_pickup',
        ]);
        $this->pendingClaim($repair, $customer)->update([
            'status' => RepairWarrantyClaim::STATUS_APPROVED, 'approved_repair_request_id' => $job->id,
        ]);
        $this->expectException(ValidationException::class);
        app(RepairPosRefundService::class)->requestRefund($source, $this->refundPayload(), $customer->id);
    }

    public function test_delivery_compensation_remains_allowed_during_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $this->pendingClaim($repair, $customer);
        $refund = app(RepairPosRefundService::class)->requestRefund($source,
            array_replace($this->refundPayload(), ['workflow_source' => 'delivery_reconciliation']), $customer->id);
        $this->assertSame('requested', $refund->status);
    }

    public function test_partial_service_refund_cannot_be_bypassed_by_stale_repair_model(): void
    {
        Storage::fake('public');
        [$repair, $customer, $source] = $this->context();
        $this->refund($repair, $source, 'succeeded', 100);
        $this->expectException(ValidationException::class);
        app(RepairWarrantyService::class)->createCustomerClaim($repair, $customer, [
            'reason_code' => 'service_defect', 'same_issue_confirmation' => true,
            'preferred_return_method' => 'walk_in', 'preferred_receive_method' => 'walk_in',
        ], [UploadedFile::fake()->create('proof.jpg', 64, 'image/jpeg')]);
    }

    public function test_intake_received_is_not_final_receipt_for_warranty(): void
    {
        [$repair, $customer] = $this->context();
        $repair->update(['status' => 'received']);
        $this->assertFalse(app(RepairWarrantyService::class)->canClaimWarranty($repair, $customer->id));
    }

    public function test_claim_creation_revalidates_current_repair_state_under_lock(): void
    {
        Storage::fake('public');
        [$repair, $customer] = $this->context();
        RepairRequest::query()->whereKey($repair->id)->update(['status' => 'in_progress']);
        $this->expectException(ValidationException::class);
        app(RepairWarrantyService::class)->createCustomerClaim($repair, $customer, [
            'reason_code' => 'service_defect', 'same_issue_confirmation' => true,
            'preferred_return_method' => 'walk_in', 'preferred_receive_method' => 'walk_in',
        ], [UploadedFile::fake()->create('proof.jpg', 64, 'image/jpeg')]);
    }

    public function test_lower_level_refund_creation_blocks_active_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-CONFLICT', 'original_repair_request_id' => $repair->id,
            'shop_owner_id' => $repair->shop_owner_id, 'customer_user_id' => $customer->id,
            'status' => RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
            'reason_code' => 'service_defect', 'same_issue_confirmation' => true,
            'preferred_return_method' => 'walk_in', 'source_channel' => 'customer_portal',
        ]);
        $this->expectException(ValidationException::class);
        app(RepairPosRefundService::class)->requestRefund($source, $this->refundPayload(), $customer->id);
    }

    public function test_lower_level_customer_refund_rejects_unfinished_paid_repair(): void
    {
        [$repair, $customer, $source] = $this->context();
        $repair->update(['status' => 'pending']);
        $this->expectException(ValidationException::class);
        app(RepairPosRefundService::class)->requestRefund($source, $this->refundPayload(), $customer->id);
    }

    public function test_refund_then_warranty_cannot_coexist(): void
    {
        Storage::fake('public');
        [$repair, $customer, $source] = $this->context();
        app(RepairPosRefundService::class)->requestRefund($source, $this->refundPayload(), $customer->id);
        $this->expectException(ValidationException::class);
        app(RepairWarrantyService::class)->createCustomerClaim($repair, $customer, [
            'reason_code' => 'service_defect', 'same_issue_confirmation' => true,
            'preferred_return_method' => 'walk_in', 'preferred_receive_method' => 'walk_in',
        ], [UploadedFile::fake()->create('proof.jpg', 64, 'image/jpeg')]);
    }

    public function test_finance_approval_rechecks_conflicting_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $refund = $this->refund($repair, $source, 'requested', 100, 'pos');
        $this->pendingClaim($repair, $customer);
        $this->expectException(ValidationException::class);
        app(RepairPosRefundService::class)->approve($refund, $customer->id, 100);
    }

    public function test_final_refund_execution_rechecks_conflicting_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $refund = $this->refund($repair, $source, 'approved', 100, 'pos');
        $this->pendingClaim($repair, $customer);
        $this->expectException(ValidationException::class);
        app(RepairPosRefundService::class)->execute($refund, $customer->id);
    }

    public function test_warranty_approval_rechecks_refund_before_creating_rework(): void
    {
        [$repair, $customer, $source] = $this->context();
        $claim = $this->pendingClaim($repair, $customer);
        $this->refund($repair, $source, 'requested', 100);
        $this->expectException(ValidationException::class);
        app(RepairWarrantyService::class)->approveClaim($claim, $customer->id);
    }

    public function test_repairer_endorsement_rechecks_conflicting_warranty(): void
    {
        [$repair, $customer, $source] = $this->context();
        $refund = $this->refund($repair, $source, 'requested', 100);
        $refund->update(['repairer_status' => 'pending']);
        $this->pendingClaim($repair, $customer);
        $this->expectException(ValidationException::class);
        app(RepairOnlineRefundWorkflowService::class)->repairerApprove($refund, $customer->id, 'Defect confirmed', 100);
    }

    public function test_customer_payload_projects_refund_conflicts_before_separate_claim_reads(): void
    {
        [$repair, $customer] = $this->context();
        $this->pendingClaim($repair, $customer);
        $this->actingAs($customer, 'user')->getJson('/api/customer/repairs')
            ->assertOk()->assertJsonPath('data.0.can_refund', false)
            ->assertJsonPath('data.0.refund_block_reason', 'Refund cannot be requested while a warranty claim is active for this repair.');
    }

    public function test_unfinished_customer_refund_is_rejected_before_payment_backfill(): void
    {
        [$repair, $customer] = $this->context();
        $repair->update(['status' => 'pending']);
        $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds")
            ->assertUnprocessable()->assertJsonPath('message', 'Service refunds can only be requested after the customer receives the completed repair.');
        $this->assertDatabaseCount('pos_refunds', 0);
    }

    public function test_gateway_submission_runs_after_resolution_reservation_transaction(): void
    {
        [$repair, $customer, $source] = $this->context();
        $repair->shopOwner->update(['paymongo_secret_key' => 'sk_test_resolution']);
        PosPaymentLine::query()->create([
            'pos_transaction_id' => $source->id, 'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pay_resolution', 'amount' => 1000, 'status' => 'paid', 'paid_at' => now(),
        ]);
        $refund = $this->refund($repair, $source, 'approved', 100, 'pos');
        $before = DB::transactionLevel();
        $providerTransactionLevel = null;
        Http::fake(function ($request) use (&$providerTransactionLevel) {
            if ($request->method() === 'POST') {
                $providerTransactionLevel = DB::transactionLevel();
                return Http::response(['data' => ['id' => 're_resolution', 'attributes' => ['status' => 'succeeded', 'amount' => 10000]]]);
            }
            return Http::response(['data' => ['attributes' => ['amount' => 100000]]]);
        });
        $result = app(RepairPosRefundService::class)->execute($refund, $customer->id, 'gateway');
        $this->assertSame('succeeded', $result->status);
        $this->assertSame($before, $providerTransactionLevel, 'The provider call must not run inside the added resolution transaction.');
    }

    public function test_rolled_back_refund_approval_does_not_send_email(): void
    {
        Mail::fake();
        [$repair, $customer, $source] = $this->context();
        $refund = $this->refund($repair, $source, 'requested', 100, 'pos');
        $refund->update(['requires_owner_approval' => false]);
        try {
            DB::transaction(function () use ($refund, $customer): void {
                app(RepairPosRefundService::class)->approve($refund, $customer->id, 100);
                throw new \RuntimeException('Roll back the surrounding workflow');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Roll back the surrounding workflow', $exception->getMessage());
        }
        $this->assertSame('requested', $refund->fresh()->status);
        Mail::assertNothingSent();
    }

    private function pendingClaim(RepairRequest $repair, User $customer): RepairWarrantyClaim
    {
        return RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-LOCKED-'.$repair->id,
            'original_repair_request_id' => $repair->id, 'shop_owner_id' => $repair->shop_owner_id,
            'customer_user_id' => $customer->id, 'status' => RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
            'reason_code' => 'service_defect', 'same_issue_confirmation' => true,
            'preferred_return_method' => 'walk_in', 'source_channel' => 'customer_portal',
            'warranty_started_at_snapshot' => now()->subDay(), 'warranty_expires_at_snapshot' => now()->addDays(29),
        ]);
    }

    private function context(): array
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id, 'user_id' => $customer->id,
            'origin_channel' => 'marketplace', 'status' => 'picked_up',
            'picked_up_at' => now()->subDay(), 'total' => 1000, 'final_total' => 1000,
            'payment_status' => 'completed', 'total_paid_amount' => 1000,
            'repair_warranty_issued' => true, 'repair_warranty_started_at' => now()->subDay(),
            'repair_warranty_expires_at' => now()->addDays(29),
            'repair_warranty_duration' => 30, 'repair_warranty_duration_unit' => 'days',
        ]);
        $source = PosTransaction::query()->create([
            'transaction_no' => 'POS-CONSISTENCY-'.$repair->id,
            'shop_owner_id' => $shop->id, 'module_type' => 'repair', 'module_reference_id' => $repair->id,
            'customer_type' => 'registered', 'customer_id' => $customer->id,
            'due_type' => 'full', 'subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'paid_amount' => 1000, 'status' => 'paid', 'paid_at' => now(),
            'metadata' => ['service_amount' => 1000, 'delivery_amount' => 0],
        ]);
        return [$repair, $customer, $source];
    }

    private function refund(RepairRequest $repair, PosTransaction $source, string $status, float $amount, string $workflow = 'online_myrepair'): PosRefund
    {
        return PosRefund::query()->create([
            'refund_no' => 'RFD-CONSISTENCY-'.$repair->id,
            'shop_owner_id' => $repair->shop_owner_id, 'module_type' => 'repair',
            'module_reference_id' => $repair->id, 'source_transaction_id' => $source->id,
            'workflow_source' => $workflow, 'request_type' => 'partial',
            'requested_amount' => $amount, 'approved_amount' => $amount,
            'reason_code' => 'service_defect', 'status' => $status,
        ]);
    }

    private function refundPayload(): array
    {
        return ['workflow_source' => 'online_myrepair', 'request_type' => 'partial',
            'requested_amount' => 100, 'reason_code' => 'service_defect'];
    }
}
