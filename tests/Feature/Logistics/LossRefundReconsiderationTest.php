<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\OrderRefundAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LossRefundReconsiderationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_a_rejected_loss_back_without_erasing_the_rejection_or_approving_payment(): void
    {
        [$owner, $refund] = $this->fixture();
        $priorActor = $refund->processed_by;
        $key = (string) Str::uuid();
        $payload = ['reason' => 'Review the missing delivery evidence and correct the assessment.', 'request_key' => $key];
        $response = $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), $payload)->assertCreated();
        $replacement = OrderRefund::findOrFail($response->json('replacementRefundId'));
        $this->assertSame('rejected', $refund->fresh()->status);
        $this->assertSame('Evidence needs correction.', $refund->fresh()->rejection_reason);
        $this->assertSame($priorActor, $refund->fresh()->processed_by);
        $this->assertSame($replacement->id, $refund->fresh()->replacement_refund_id);
        $this->getJson("/api/shop-owner/refunds/{$refund->id}")->assertOk()
            ->assertJsonPath('correctionInstructions', $payload['reason'])->assertJsonPath('replacementRefundId', $replacement->id);
        $this->assertSame('requested', $replacement->status);
        $this->assertSame('pending', $replacement->shop_owner_status);
        $this->assertSame('pending', $replacement->finance_status);
        $this->assertFalse($replacement->requires_owner_approval);
        $this->assertNull($replacement->staff_approved_by);
        $this->assertSame('not_required', $replacement->return_status);
        $this->assertFalse(app(\App\Services\OrderRefundService::class)->canExecuteApprovedRefund($replacement));
        $this->postJson($this->endpoint($refund), $payload)->assertOk()->assertJsonPath('replacementRefundId', $replacement->id);
        $this->assertSame(2, OrderRefund::where('order_id', $refund->order_id)->count());
        $this->postJson($this->endpoint($refund), [...$payload, 'reason' => 'Changed facts with the same request identity'])->assertUnprocessable();
    }

    public function test_rejected_loss_appears_in_owner_queue_even_when_normal_owner_payment_approval_is_optional(): void
    {
        [$owner, $refund] = $this->fixture();
        $items = app(OrderRefundAttentionAdapter::class)->read($owner, new OwnerAttentionQuery);
        $this->assertContains($refund->id, collect($items->items)->pluck('sourceId')->all());
        $this->actingAs($owner, 'shop_owner')->getJson("/api/shop-owner/refunds/{$refund->id}")
            ->assertOk()->assertJsonPath('canRequestCorrection', true)->assertJsonPath('rejectionReason', 'Evidence needs correction.');
    }

    public function test_employees_and_foreign_owners_cannot_authorize_correction(): void
    {
        [$owner, $refund] = $this->fixture();
        $payload = ['reason' => 'Request a corrected assessment.', 'request_key' => (string) Str::uuid()];
        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->actingAs($employee, 'user')->postJson($this->endpoint($refund), $payload)->assertUnauthorized();
        \Illuminate\Support\Facades\Auth::guard('user')->logout();
        $foreign = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        foreach (['finance', 'retail_operations', 'logistics'] as $module) {
            ShopOwnerModule::updateOrCreate(['shop_owner_id' => $foreign->id, 'module_key' => $module], ['enabled' => true]);
        }
        $this->actingAs($foreign, 'shop_owner')->postJson($this->endpoint($refund), $payload)->assertNotFound();
        $this->assertNull($refund->fresh()->replacement_refund_id);
    }

    public function test_correction_cannot_over_reserve_a_payment_or_reopen_a_non_loss_rejection(): void
    {
        [$owner, $refund] = $this->fixture();
        OrderRefund::factory()->create(['order_id' => $refund->order_id, 'shop_owner_id' => $owner->id, 'status' => 'pending_approval']);
        $payload = ['reason' => 'Request a corrected assessment.', 'request_key' => (string) Str::uuid()];
        $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), $payload)->assertUnprocessable();
        $this->assertNull($refund->fresh()->replacement_refund_id);
        $refund->update(['reason_code' => 'defective_item']);
        $this->postJson($this->endpoint($refund), $payload)->assertUnprocessable();
    }

    public function test_repeated_rejections_require_a_new_owner_decision_and_preserve_owner_policy(): void
    {
        [$owner, $refund] = $this->fixture();
        $refund->update(['requires_owner_approval' => null]);
        $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), ['reason' => 'Reassess the first evidence discrepancy.', 'request_key' => (string) Str::uuid()])->assertCreated();
        $second = $refund->fresh()->replacementRefund;
        $this->assertTrue($second->requires_owner_approval);
        $second->update(['status' => 'rejected', 'finance_status' => 'rejected', 'rejection_reason' => 'A further discrepancy.']);
        $this->postJson($this->endpoint($second), ['reason' => 'Reassess the additional evidence discrepancy.', 'request_key' => (string) Str::uuid()])->assertCreated();
        $third = $second->fresh()->replacementRefund;
        $this->assertSame('requested', $third->status);
        $this->assertSame('pending', $third->finance_status);
        $this->assertNull($third->staff_approved_by);
        $this->assertSame('A further discrepancy.', $second->fresh()->rejection_reason);
        $this->assertSame($refund->reason_note, $third->reason_note);
        $this->assertSame('Reassess the additional evidence discrepancy.', $third->correctionSource->recovery_resolution_reason);
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'order_refund.correction_requested')->count());
    }

    public function test_provider_touched_claims_and_invalid_instructions_cannot_be_reconsidered(): void
    {
        [$owner, $refund] = $this->fixture();
        $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), ['reason' => 'short', 'request_key' => 'invalid'])->assertUnprocessable();
        $refund->update(['provider_payout_id' => 'synthetic-provider-attempt']);
        $this->postJson($this->endpoint($refund), ['reason' => 'Reassess the payment evidence.', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertNull($refund->fresh()->replacement_refund_id);
    }

    public function test_cod_correction_requires_collected_cash_and_never_settles_it(): void
    {
        [$owner, $refund] = $this->fixture();
        $refund->order->update(['payment_method' => 'cod']);
        $payload = ['reason' => 'Reassess the collected cash evidence.', 'request_key' => (string) Str::uuid()];
        $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), $payload)->assertUnprocessable();
        $collection = \App\Models\CodCollection::create(['shop_owner_id' => $owner->id, 'order_id' => $refund->order_id,
            'expected_amount' => 1000, 'collected_amount' => 1000, 'status' => 'cash_collected']);
        $this->postJson($this->endpoint($refund), $payload)->assertCreated();
        $this->assertSame('cash_collected', $collection->fresh()->status);
        $this->assertNull($collection->fresh()->settled_at);
        $this->assertFalse(app(\App\Services\OrderRefundService::class)->canExecuteApprovedRefund($refund->fresh()->replacementRefund));
    }

    public function test_loss_marker_alone_does_not_authorize_reconsideration(): void
    {
        [$owner, $refund] = $this->fixture();
        ShipmentLeg::where('shipment_id', Shipment::where('source_id', $refund->order_id)->value('id'))->update(['resolution_type' => null]);
        $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), [
            'reason' => 'Reassess synthetic disputed evidence.', 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->assertNull($refund->fresh()->replacement_refund_id);
    }

    public function test_staff_rejection_correction_remains_visible_in_owner_history_when_monetary_approval_is_optional(): void
    {
        [$owner, $refund] = $this->fixture();
        $refund->update(['finance_status' => 'pending']);
        $this->actingAs($owner, 'shop_owner')->postJson($this->endpoint($refund), [
            'reason' => 'Reassess the Staff rejection evidence.', 'request_key' => (string) Str::uuid(),
        ])->assertCreated();
        $history = app(\App\Services\OwnerActionCenter\OwnerApprovalHistoryService::class)->read($owner, new OwnerAttentionQuery);
        $entry = collect($history->items)->firstWhere('sourceId', $refund->id);
        $this->assertNotNull($entry);
        $this->assertStringContainsString('sent for correction', $entry->conciseSummary);
        $this->getJson("/api/shop-owner/refunds/{$refund->id}")->assertOk()->assertJsonPath('canRequestCorrection', false);
    }

    private function endpoint(OrderRefund $refund): string
    {
        return "/api/shop-owner/refunds/{$refund->id}/request-correction";
    }

    private function fixture(): array
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        foreach (['finance', 'retail_operations', 'logistics'] as $module) {
            ShopOwnerModule::updateOrCreate(['shop_owner_id' => $owner->id, 'module_key' => $module], ['enabled' => true]);
        }
        \App\Models\ProcurementSettings::updateOrCreate(['shop_owner_id' => $owner->id],
            ['settings_json' => ['approval_pages' => ['refund_approval' => ['enabled' => false]]]]);
        $order = Order::factory()->create(['shop_owner_id' => $owner->id, 'payment_status' => 'paid', 'payment_method' => 'paymongo', 'paymongo_payment_id' => 'synthetic-reconsideration', 'total_amount' => 1000, 'shipping_fee' => 0]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $owner->id, 'source_type' => 'order', 'source_id' => $order->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'cancelled', 'resolution_type' => 'loss_confirmed']);
        $refund = OrderRefund::factory()->create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'shop_owner_id' => $owner->id,
            'flow_type' => 'request_approval', 'status' => 'rejected', 'reason_code' => 'delivery_loss_confirmed', 'rejection_reason' => 'Evidence needs correction.',
            'shop_owner_status' => 'pending', 'finance_status' => 'rejected', 'requires_owner_approval' => false, 'return_status' => 'not_required',
            'amount' => 1000, 'payment_gateway' => 'paymongo', 'currency' => 'PHP', 'paymongo_payment_id' => $order->paymongo_payment_id,
            'processed_by' => User::factory()->create(['shop_owner_id' => $owner->id])->id, 'payout_status' => 'not_started',
            'idempotency_key' => "delivery-loss-confirmed:{$order->id}:{$leg->id}"]);

        return [$owner, $refund];
    }
}
