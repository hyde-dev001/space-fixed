<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\PosPaymentLine;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairOnlineRefundAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_refund_submission_enters_repairer_pending_stage(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-ONLINE-RFD-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000123',
            'shoe_type' => 'Sneakers',
            'description' => 'Online refund route auth test repair',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 500,
            'final_total' => 500,
            'status' => 'for_release',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 500,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-ONLINE-RFD-AUTH-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_tdd_auth_001',
            'amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/refunds", [
                'source_transaction_id' => $source->id,
                'request_type' => 'full',
                'requested_amount' => 500,
                'reason_code' => 'service_defect',
                'evidence' => [['type' => 'photo', 'url' => 'https://cdn/app/proof-1.jpg']],
            ])
            ->assertOk()
            ->assertJsonPath('data.repairer_status', 'pending')
            ->assertJsonPath('data.workflow_source', 'online_myrepair');

        $this->assertDatabaseHas('pos_refunds', [
            'source_transaction_id' => $source->id,
            'workflow_source' => 'online_myrepair',
        ]);
    }

    #[Test]
    public function customer_cannot_access_repairer_refund_queue(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'user')
            ->getJson('/api/repairer/refunds')
            ->assertForbidden();
    }
}
