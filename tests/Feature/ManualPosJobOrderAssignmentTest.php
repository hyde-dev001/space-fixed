<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualPosJobOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repairer_actor_self_assigns_manual_pos_checkout(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        Role::findOrCreate('Repairer', 'user');
        $repairer->assignRole('Repairer');

        $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-self-assign-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Self Assign Test',
            'walk_in_phone' => '09170000011',
            'manual_repair_subtotal' => 600,
            'manual_service_summary' => 'Manual POS self assign',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 300],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertSame($repairer->id, (int) $repair->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $repair->status);
    }
}
