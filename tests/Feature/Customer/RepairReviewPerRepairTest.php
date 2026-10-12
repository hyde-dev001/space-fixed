<?php

namespace Tests\Feature\Customer;

use App\Models\RepairRequest;
use App\Models\RepairReview;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairReviewPerRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_review_each_picked_up_repair_once(): void
    {
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create();
        $repairAttributes = [
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'picked_up',
        ];
        $firstRepair = RepairRequest::factory()->create($repairAttributes);
        $secondRepair = RepairRequest::factory()->create($repairAttributes);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$firstRepair->id}/review", [
                'rating' => 5,
                'review_text' => 'First repair review',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$secondRepair->id}/review", [
                'rating' => 4,
                'review_text' => 'Second repair review',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(2, RepairReview::where('user_id', $customer->id)->count());

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$firstRepair->id}/review", [
                'rating' => 3,
                'review_text' => 'Duplicate review',
            ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_customer_cannot_review_another_customers_repair(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $repairOwner = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $repair = RepairRequest::factory()->create([
            'user_id' => $repairOwner->id,
            'shop_owner_id' => $owner->id,
            'status' => 'picked_up',
        ]);

        $this->actingAs($otherCustomer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/review", [
                'rating' => 5,
                'review_text' => 'Unauthorized review',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('repair_reviews', 0);
    }
}
