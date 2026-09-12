<?php

namespace Tests\Feature\Repair;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPaymentSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_collection_exposes_total_balance_separately_from_current_collectible_amount(): void
    {
        $repair = $this->repair([
            'status' => 'repairer_accepted',
            'payment_status' => 'pending',
            'total_paid_amount' => 0,
        ]);

        $summary = app(PaymentSettlementService::class)->repairCollectionSummary($repair);

        $this->assertTrue($summary['collectible']);
        $this->assertSame('deposit', $summary['due_type']);
        $this->assertSame('initial', $summary['phase']);
        $this->assertSame('500.00', number_format($summary['collectible_amount'], 2, '.', ''));
        $this->assertSame('1000.00', number_format($summary['outstanding_balance'], 2, '.', ''));
        $this->assertFalse($summary['fully_paid']);
    }

    public function test_ready_repair_exposes_the_final_balance_as_the_current_collectible_amount(): void
    {
        $repair = $this->repair([
            'status' => 'ready_for_pickup',
            'payment_status' => 'paid',
            'total_paid_amount' => 500,
        ]);

        $summary = app(PaymentSettlementService::class)->repairCollectionSummary($repair);

        $this->assertTrue($summary['collectible']);
        $this->assertSame('balance', $summary['due_type']);
        $this->assertSame('final', $summary['phase']);
        $this->assertSame('500.00', number_format($summary['collectible_amount'], 2, '.', ''));
        $this->assertSame('500.00', number_format($summary['outstanding_balance'], 2, '.', ''));
        $this->assertFalse($summary['fully_paid']);
    }

    public function test_fully_paid_cancelled_and_rejected_repairs_are_not_collectible(): void
    {
        $fullyPaid = $this->repair([
            'status' => 'ready_for_pickup',
            'payment_status' => 'completed',
            'total_paid_amount' => 1000,
        ]);
        $cancelled = $this->repair([
            'status' => 'cancelled',
            'payment_status' => 'pending',
        ]);
        $rejected = $this->repair([
            'status' => 'rejected',
            'payment_status' => 'pending',
        ]);
        $service = app(PaymentSettlementService::class);

        foreach ([$fullyPaid, $cancelled, $rejected] as $repair) {
            $summary = $service->repairCollectionSummary($repair);

            $this->assertFalse($summary['collectible']);
            $this->assertSame('0.00', number_format($summary['collectible_amount'], 2, '.', ''));
            $this->assertSame('0.00', number_format($summary['outstanding_balance'], 2, '.', ''));
        }

        $this->assertTrue($service->repairCollectionSummary($fullyPaid)['fully_paid']);
    }

    public function test_recovery_only_phase_is_not_labeled_as_an_ordinary_pos_collection(): void
    {
        $repair = $this->repair([
            'status' => 'repairer_accepted',
            'payment_status' => 'pending',
            'total_paid_amount' => 0,
            'logistics_payment_reconciliation' => [
                'status' => 'resolved',
                'entries' => [[
                    'type' => 'pickup_recovery',
                    'status' => 'awaiting_payment',
                    'recovery_key' => 'pickup-recovery-1',
                    'plan_key' => 'pickup-plan-1',
                ]],
            ],
        ]);

        $summary = app(PaymentSettlementService::class)->repairCollectionSummary($repair);

        $this->assertSame('pickup_retry', $summary['due_type']);
        $this->assertSame('pickup_retry', $summary['phase']);
        $this->assertNotContains($summary['due_type'], ['deposit', 'full', 'balance']);
    }

    public function test_repair_payloads_expose_authoritative_collection_summary_and_pos_filters_non_collectible_rows(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        $initial = $this->apiRepair($shop, $customer, $repairer, [
            'request_id' => 'REP-SUMMARY-API-INITIAL',
            'status' => 'repairer_accepted',
            'payment_status' => 'pending',
            'total_paid_amount' => 0,
        ]);
        $ready = $this->apiRepair($shop, $customer, $repairer, [
            'request_id' => 'REP-SUMMARY-API-READY',
            'status' => 'ready_for_pickup',
            'payment_status' => 'paid',
            'total_paid_amount' => 500,
        ]);
        $this->apiRepair($shop, $customer, $repairer, [
            'request_id' => 'REP-SUMMARY-API-PAID',
            'status' => 'ready_for_pickup',
            'payment_status' => 'completed',
            'total_paid_amount' => 1000,
        ]);
        $this->apiRepair($shop, $customer, $repairer, [
            'request_id' => 'REP-SUMMARY-API-CANCELLED',
            'status' => 'cancelled',
        ]);
        $this->apiRepair($shop, $customer, $repairer, [
            'request_id' => 'REP-SUMMARY-API-REJECTED',
            'status' => 'rejected',
        ]);

        $posResponse = $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/repairs?scope=pos_checkout')
            ->assertOk();
        $posRows = collect($posResponse->json('data'))->keyBy('id');

        $this->assertTrue($posRows->has($initial->id));
        $this->assertTrue($posRows->has($ready->id));
        $this->assertSame(
            ['REP-SUMMARY-API-INITIAL', 'REP-SUMMARY-API-READY'],
            $posRows->pluck('request_id')->sort()->values()->all(),
        );
        $this->assertSame('deposit', $posRows[$initial->id]['due_type']);
        $this->assertSame('500.00', number_format((float) $posRows[$initial->id]['collectible_amount'], 2, '.', ''));
        $this->assertSame('1000.00', number_format((float) $posRows[$initial->id]['outstanding_balance'], 2, '.', ''));
        $this->assertSame('balance', $posRows[$ready->id]['due_type']);
        $this->assertSame('500.00', number_format((float) $posRows[$ready->id]['collectible_amount'], 2, '.', ''));

        $repairerRow = collect($this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/repairs')
            ->assertOk()
            ->json('data'))->firstWhere('id', $ready->id);
        $this->assertSame('final', $repairerRow['phase']);
        $this->assertSame('500.00', number_format((float) $repairerRow['outstanding_balance'], 2, '.', ''));
        $this->assertFalse($repairerRow['fully_paid']);

        $customerRow = collect($this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->json('data'))->firstWhere('id', $ready->id);
        $this->assertSame('balance', $customerRow['due_type']);
        $this->assertSame('500.00', number_format((float) $customerRow['outstanding_balance'], 2, '.', ''));
        $this->assertFalse($customerRow['fully_paid']);
    }

    private function apiRepair(ShopOwner $shop, User $customer, User $repairer, array $overrides = []): RepairRequest
    {
        static $sequence = 0;
        $sequence++;

        return RepairRequest::factory()->create(array_merge([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'request_id' => 'REP-SUMMARY-API-'.$sequence,
            'customer_name' => $customer->name,
            'status' => 'repairer_accepted',
            'payment_status' => 'pending',
            'payment_enabled' => true,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'total' => 1000,
            'final_total' => 1000,
            'total_paid_amount' => 0,
            'delivery_method' => 'walk_in',
            'intake_delivery_method' => 'walk_in',
            'return_delivery_method' => 'customer_pickup',
        ], $overrides));
    }

    private function repair(array $overrides = []): RepairRequest
    {
        static $sequence = 0;
        $sequence++;

        return RepairRequest::create(array_merge([
            'request_id' => 'REP-SUMMARY-'.$sequence,
            'customer_name' => 'Summary Customer',
            'email' => 'summary-'.$sequence.'@example.test',
            'phone' => '0917000'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'shoe_type' => 'Sneakers',
            'description' => 'Payment summary regression',
            'images' => [],
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'repairer_accepted',
            'payment_enabled' => true,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
            'total_paid_amount' => 0,
            'intake_delivery_method' => 'walk_in',
            'return_delivery_method' => 'customer_pickup',
            'delivery_method' => 'walk_in',
        ], $overrides));
    }
}
