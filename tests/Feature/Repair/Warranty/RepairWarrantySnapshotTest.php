<?php

namespace Tests\Feature\Repair\Warranty;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RepairWarrantyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RepairWarrantySnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_handover_snapshots_week_based_warranty_and_keeps_it_after_setting_changes(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'warranty_enabled' => true,
            'repair_warranty_days' => 4,
            'repair_warranty_duration_unit' => 'weeks',
        ]);
        $handoverAt = Carbon::parse('2026-09-20 14:30:00');
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'picked_up',
            'picked_up_at' => $handoverAt,
        ]);

        $issued = app(RepairWarrantyService::class)->issueAtHandover($repair);

        $this->assertTrue((bool) $issued->repair_warranty_issued);
        $this->assertEquals($handoverAt, $issued->repair_warranty_started_at);
        $this->assertSame(
            $handoverAt->copy()->addWeeks(4)->endOfDay()->format('Y-m-d H:i:s'),
            $issued->repair_warranty_expires_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(4, (int) $issued->repair_warranty_duration);
        $this->assertSame('weeks', (string) $issued->repair_warranty_duration_unit);

        $shopOwner->update([
            'warranty_enabled' => false,
            'repair_warranty_days' => 1,
            'repair_warranty_duration_unit' => 'days',
        ]);

        $again = app(RepairWarrantyService::class)->issueAtHandover($issued->fresh());

        $this->assertEquals($issued->repair_warranty_expires_at, $again->repair_warranty_expires_at);
        $this->assertSame(4, (int) $again->repair_warranty_duration);
        $this->assertSame('weeks', (string) $again->repair_warranty_duration_unit);
    }

    public function test_disabled_warranty_does_not_issue_a_snapshot(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'warranty_enabled' => false,
        ]);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'picked_up',
            'picked_up_at' => now(),
        ]);

        $issued = app(RepairWarrantyService::class)->issueAtHandover($repair);

        $this->assertFalse((bool) $issued->repair_warranty_issued);
        $this->assertNull($issued->repair_warranty_started_at);
        $this->assertNull($issued->repair_warranty_expires_at);
    }

    public function test_claim_eligibility_requires_an_issued_snapshot(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'warranty_enabled' => true,
        ]);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDay(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Warranty was not issued for this repair request.');

        app(RepairWarrantyService::class)->validateEligibility($repair);
    }

    public function test_customer_repairs_payload_exposes_only_issued_warranty_state(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'warranty_enabled' => true,
        ]);
        $customer = User::factory()->create();
        $issuedRepair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'picked_up',
            'picked_up_at' => now(),
        ]);
        app(RepairWarrantyService::class)->issueAtHandover($issuedRepair);
        RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');

        $response->assertOk();
        $payload = collect($response->json('data'))
            ->keyBy('id');

        $this->assertTrue((bool) data_get($payload->get($issuedRepair->id), 'warranty.issued'));
        $this->assertTrue((bool) data_get($payload->get($issuedRepair->id), 'warranty.active'));
        $this->assertTrue((bool) data_get($payload->get($issuedRepair->id), 'warranty.can_claim'));
        $this->assertNull(data_get($payload->firstWhere('id', '!=', $issuedRepair->id), 'warranty'));
    }
}
