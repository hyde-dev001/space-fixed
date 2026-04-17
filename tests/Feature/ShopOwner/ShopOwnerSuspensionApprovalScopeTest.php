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
}