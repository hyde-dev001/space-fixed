<?php

namespace Tests\Feature\Finance;

use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPriceApprovalSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_price_three_step_runtime_smoke_pass(): void
    {
        $this->withoutMiddleware();

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $requester = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $finance = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $service = RepairService::create([
            'name' => 'Heel Rebuild',
            'category' => 'Restoration',
            'price' => 1200.00,
            'old_price' => 1200.00,
            'duration' => '3 days',
            'description' => 'Requested increase due to material cost update',
            'status' => 'Under Review',
            'shop_owner_id' => $shopOwner->id,
            'created_by' => $requester->id,
            'updated_by' => $requester->id,
            'finance_notes' => '1750.50',
        ]);

        $step1Payload = ['notes' => '1680.25'];
        $step1 = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-price-changes/{$service->id}/approve", $step1Payload);

        $step1->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Service approved by Finance. Pending Shop Owner approval.',
        ]);

        $service->refresh();
        $step1State = [
            'status' => $service->status,
            'price' => (string) $service->price,
            'finance_notes' => $service->finance_notes,
        ];

        $step2Payload = [];
        $step2 = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repair-price-changes/{$service->id}/approve", $step2Payload);

        $step2->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Service approved by Shop Owner. Forwarding to Finance for final approval.',
        ]);

        $service->refresh();
        $step2State = [
            'status' => $service->status,
            'price' => (string) $service->price,
            'finance_notes' => $service->finance_notes,
        ];

        $step3Payload = ['notes' => 'Final sign-off by finance'];
        $step3 = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-price-changes/{$service->id}/approve-final", $step3Payload);

        $step3->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Service price change approved and applied.',
        ]);

        $service->refresh();
        $step3State = [
            'status' => $service->status,
            'price' => (string) $service->price,
            'finance_notes' => $service->finance_notes,
        ];

        fwrite(STDOUT, "\n--- REPAIR PRICE SMOKE PAYLOADS ---\n");
        fwrite(STDOUT, 'STEP 1 REQUEST: ' . json_encode($step1Payload) . "\n");
        fwrite(STDOUT, 'STEP 1 RESPONSE: ' . json_encode($step1->json()) . "\n");
        fwrite(STDOUT, 'STEP 1 DB STATUS: ' . $step1State['status'] . ", price=" . $step1State['price'] . ", finance_notes=" . ($step1State['finance_notes'] ?? 'null') . "\n");

        fwrite(STDOUT, 'STEP 2 REQUEST: ' . json_encode($step2Payload) . "\n");
        fwrite(STDOUT, 'STEP 2 RESPONSE: ' . json_encode($step2->json()) . "\n");
        fwrite(STDOUT, 'STEP 2 DB STATUS: ' . $step2State['status'] . ", price=" . $step2State['price'] . ", finance_notes=" . ($step2State['finance_notes'] ?? 'null') . "\n");

        fwrite(STDOUT, 'STEP 3 REQUEST: ' . json_encode($step3Payload) . "\n");
        fwrite(STDOUT, 'STEP 3 RESPONSE: ' . json_encode($step3->json()) . "\n");
        fwrite(STDOUT, 'STEP 3 DB STATUS: ' . $step3State['status'] . ", price=" . $step3State['price'] . ", finance_notes=" . ($step3State['finance_notes'] ?? 'null') . "\n");
        fwrite(STDOUT, "--- END REPAIR PRICE SMOKE ---\n\n");

        $this->assertSame('Active', $service->status);
    }

    public function test_repair_final_approval_recovers_missing_proposed_price_from_activity_log(): void
    {
        $this->withoutMiddleware();

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $requester = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $finance = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $service = RepairService::create([
            'name' => 'Edge Repaint',
            'category' => 'Customization',
            'price' => 900.00,
            'old_price' => 900.00,
            'duration' => '2 days',
            'description' => 'Price update',
            'status' => 'Under Review',
            'shop_owner_id' => $shopOwner->id,
            'created_by' => $requester->id,
            'updated_by' => $requester->id,
            'finance_notes' => '1300.00',
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-price-changes/{$service->id}/approve", [
                'notes' => null,
            ])
            ->assertStatus(200);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repair-price-changes/{$service->id}/approve", [])
            ->assertStatus(200);

        // Simulate a legacy broken in-flight row where proposed price was lost.
        $service->refresh();
        $service->finance_notes = null;
        $service->save();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-price-changes/{$service->id}/approve-final", [
                'notes' => 'Recovered final apply',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Service price change approved and applied.',
            ]);

        $service->refresh();
        $this->assertSame('Active', $service->status);
        $this->assertSame('1300.00', (string) $service->price);
    }
}
