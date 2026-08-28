<?php

namespace Tests\Feature\ShopOwner;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerSuspensionApprovalScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_owner_only_sees_suspension_requests_from_their_own_shop(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $otherShopOwner = ShopOwner::factory()->approved()->create();

        $myEmployee = Employee::factory()->for($shopOwner)->create();
        $otherEmployee = Employee::factory()->for($otherShopOwner)->create();

        $myRequest = SuspensionRequest::factory()
            ->for($myEmployee)
            ->create([
                'status' => SuspensionStatus::PENDING_OWNER,
                'manager_status' => 'approved',
            ]);

        $otherRequest = SuspensionRequest::factory()
            ->for($otherEmployee)
            ->create([
                'status' => SuspensionStatus::PENDING_OWNER,
                'manager_status' => 'approved',
            ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/suspension-requests');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $myRequest->id]);
        $response->assertJsonMissing(['id' => $otherRequest->id]);
    }

    #[Test]
    public function shop_owner_cannot_review_another_shops_suspension_request(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $otherShopOwner = ShopOwner::factory()->approved()->create();

        $otherEmployee = Employee::factory()->for($otherShopOwner)->create();

        $otherRequest = SuspensionRequest::factory()
            ->for($otherEmployee)
            ->create([
                'status' => SuspensionStatus::PENDING_OWNER,
                'manager_status' => 'approved',
            ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$otherRequest->id}/review", [
                'action' => 'approve',
                'note' => 'Approved',
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function company_shop_owner_can_approve_a_manager_approved_suspension_request(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $employee = Employee::factory()->active()->for($shopOwner)->create();
        $request = SuspensionRequest::factory()
            ->for($employee)
            ->create([
                'status' => SuspensionStatus::PENDING_OWNER,
                'manager_status' => 'approved',
            ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$request->id}/review", [
                'action' => 'approve',
                'note' => 'Approved by the shop owner.',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('suspension_requests', [
            'id' => $request->id,
            'status' => SuspensionStatus::APPROVED->value,
            'owner_id' => $shopOwner->id,
            'owner_status' => 'approved',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status' => 'suspended',
        ]);
    }

    #[Test]
    public function shop_owner_repeated_review_returns_a_stable_conflict(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $employee = Employee::factory()->active()->for($shopOwner)->create();
        $request = SuspensionRequest::factory()
            ->for($employee)
            ->create([
                'status' => SuspensionStatus::PENDING_OWNER,
                'manager_status' => 'approved',
            ]);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$request->id}/review", [
                'action' => 'approve',
                'note' => 'Approve once.',
            ])
            ->assertOk();

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$request->id}/review", [
                'action' => 'reject',
                'note' => 'A second decision is not allowed.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUSPENSION_REQUEST_ALREADY_DECIDED');

        $this->assertSame(SuspensionStatus::APPROVED, $request->fresh()->status);
    }
}
