<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RepairPosRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RepairPosRefundQueueProjectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repair_queue_uses_shop_reference_and_resolves_customer_and_repair_details(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        try {
            $shopA = ShopOwner::factory()->approved()->create([
                'business_type' => 'repair',
                'registration_type' => 'individual',
            ]);
            $shopB = ShopOwner::factory()->approved()->create([
                'business_type' => 'repair',
                'registration_type' => 'individual',
            ]);
            $customerA = User::factory()->create([
                'name' => 'Maria Santos',
                'email' => 'maria@example.test',
                'phone' => '09171234567',
            ]);
            $customerB = User::factory()->create([
                'name' => 'Other Customer',
                'email' => 'other@example.test',
                'phone' => '09170000000',
            ]);

            $repairA = $this->repair($shopA, $customerA, [
                'customer_name' => '',
                'email' => 'N/A',
                'phone' => '',
                'shoe_type' => 'Sneakers',
                'brand' => 'Nike',
                'description' => 'Full sole repair',
            ]);
            $serviceA = RepairService::create([
                'shop_owner_id' => $shopA->id,
                'name' => 'Sole restoration',
                'category' => 'Restoration',
                'price' => 1500,
                'duration' => '3 days',
                'status' => 'Active',
            ]);
            $repairA->services()->attach($serviceA->id);

            $repairB = $this->repair($shopB, $customerB);
            $sourceA = $this->transaction($shopA, $repairA, 'POS-QUEUE-A');
            $sourceB = $this->transaction($shopB, $repairB, 'POS-QUEUE-B');

            $refundA = app(RepairPosRefundService::class)->requestRefund($sourceA, [
                'request_type' => 'full',
                'requested_amount' => 500,
                'reason_code' => 'service_defect',
                'reason_notes' => 'Customer reported a sole separation.',
            ], 0);
            app(RepairPosRefundService::class)->requestRefund($sourceB, [
                'request_type' => 'full',
                'requested_amount' => 500,
                'reason_code' => 'service_defect',
            ], 0);

            $response = $this->actingAs($shopA, 'shop_owner')
                ->getJson('/api/repair-pos/refunds/queue?include_history=1');

            $response->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $refundA->id)
                ->assertJsonPath('data.0.refund_reference', 'RFD-2026-0001')
                ->assertJsonPath('data.0.repairRequest.request_id', $repairA->request_id)
                ->assertJsonPath('data.0.repairRequest.customer_name', 'Maria Santos')
                ->assertJsonPath('data.0.repairRequest.customer_email', 'maria@example.test')
                ->assertJsonPath('data.0.repairRequest.customer_phone', '09171234567')
                ->assertJsonPath('data.0.repairRequest.shoe_type', 'Sneakers')
                ->assertJsonPath('data.0.repairRequest.brand', 'Nike')
                ->assertJsonPath('data.0.repairRequest.description', 'Full sole repair')
                ->assertJsonPath('data.0.repairRequest.service_name', 'Sole restoration')
                ->assertJsonPath('data.0.reason_code', 'service_defect')
                ->assertJsonPath('data.0.requested_amount', '500.00');

            $this->assertDatabaseHas('pos_refunds', [
                'id' => $refundA->id,
                'shop_refund_reference' => 'RFD-2026-0001',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function repair(ShopOwner $shop, User $customer, array $overrides = []): RepairRequest
    {
        return RepairRequest::create(array_merge([
            'request_id' => 'REP-QUEUE-' . $shop->id . '-' . $customer->id,
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'shoe_type' => 'Boots',
            'brand' => 'Adidas',
            'description' => 'Repair queue test',
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'images' => [],
            'total' => 500,
            'final_total' => 500,
            'status' => 'completed',
            'payment_policy' => 'full_upfront',
        ], $overrides));
    }

    private function transaction(ShopOwner $shop, RepairRequest $repair, string $number): PosTransaction
    {
        return PosTransaction::create([
            'transaction_no' => $number,
            'shop_owner_id' => $shop->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $repair->user_id,
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
