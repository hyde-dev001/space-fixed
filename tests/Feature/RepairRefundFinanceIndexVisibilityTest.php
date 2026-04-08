<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RepairRefundFinanceIndexVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function finance_index_hides_online_repair_refunds_until_repairer_approval(): void
    {
        Permission::findOrCreate('access-refund-approval', 'user');

        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $finance */
        $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $finance->givePermissionTo('access-refund-approval');

        /** @var User $customer */
        $customer = User::factory()->create();

        $pendingOnlineSource = $this->createRepairSourceTransaction($shopOwner->id, $customer->id, 801);
        $approvedOnlineSource = $this->createRepairSourceTransaction($shopOwner->id, $customer->id, 802);
        $pendingPosSource = $this->createRepairSourceTransaction($shopOwner->id, $customer->id, 803);

        $pendingOnlineRefund = PosRefund::create([
            'refund_no' => 'RFD-FIN-VIS-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $pendingOnlineSource->id,
            'module_type' => 'repair',
            'module_reference_id' => 801,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $approvedOnlineRefund = PosRefund::create([
            'refund_no' => 'RFD-FIN-VIS-002',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $approvedOnlineSource->id,
            'module_type' => 'repair',
            'module_reference_id' => 802,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'approved',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $pendingPosRefund = PosRefund::create([
            'refund_no' => 'RFD-FIN-VIS-003',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $pendingPosSource->id,
            'module_type' => 'repair',
            'module_reference_id' => 803,
            'workflow_source' => 'pos',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/repair-refunds?status=all');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($approvedOnlineRefund->id, $ids);
        $this->assertContains($pendingPosRefund->id, $ids);
        $this->assertNotContains($pendingOnlineRefund->id, $ids);
    }

    private function createRepairSourceTransaction(int $shopOwnerId, int $customerId, int $moduleReferenceId): PosTransaction
    {
        return PosTransaction::create([
            'transaction_no' => 'POS-FIN-VIS-' . $moduleReferenceId,
            'shop_owner_id' => $shopOwnerId,
            'module_type' => 'repair',
            'module_reference_id' => $moduleReferenceId,
            'customer_type' => 'registered',
            'customer_id' => $customerId,
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
