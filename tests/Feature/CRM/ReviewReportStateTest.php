<?php

namespace Tests\Feature\CRM;

use App\Models\ReviewReport;
use App\Models\RepairRequest;
use App\Models\RepairReview;
use App\Models\ShopOwner;
use App\Models\ShopReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReviewReportStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_state_survives_reload_and_dismissal_without_blocking_a_different_review(): void
    {
        Permission::findOrCreate('access-customer-support', 'user');
        $shop = ShopOwner::factory()->approved()->create();
        $crm = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'CRM', 'status' => 'active']);
        $crm->givePermissionTo('access-customer-support');
        $customer = User::factory()->create();
        $a = ShopReview::create(['shop_owner_id' => $shop->id, 'user_id' => $customer->id, 'rating' => 1, 'comment' => 'A']);
        $repair = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'user_id' => $customer->id]);
        $b = RepairReview::create(['repair_request_id' => $repair->id, 'shop_owner_id' => $shop->id, 'user_id' => $customer->id, 'rating' => 2, 'review_text' => 'B']);
        $this->actingAs($crm, 'user');
        $this->clockInEmployee($crm);
        $before = $this->getJson('/api/crm/reviews')->assertOk()->json('reviews.data');
        $this->assertFalse(collect($before)->firstWhere('reviewId', 'shop_'.$a->id)['is_reported'] ?? null);
        $payload = ['review_id' => 'shop_'.$a->id, 'reason' => 'spam'];
        $this->postJson('/api/crm/reviews/report', $payload)->assertOk()->assertJsonPath('is_reported', true);
        ReviewReport::query()->sole()->update(['status' => 'dismissed']);
        $after = $this->getJson('/api/crm/reviews')->assertOk()->json('reviews.data');
        $this->assertTrue(collect($after)->firstWhere('reviewId', 'shop_'.$a->id)['is_reported']);
        $this->assertFalse(collect($after)->firstWhere('reviewId', 'repair_'.$b->id)['is_reported']);
        $this->postJson('/api/crm/reviews/report', $payload)->assertConflict();
        $this->postJson('/api/crm/reviews/report', ['review_id' => 'repair_'.$b->id, 'reason' => 'spam'])->assertOk();
        $this->assertDatabaseCount('review_reports', 2);
    }

    public function test_owner_and_crm_share_history_and_cross_shop_requests_are_rejected(): void
    {
        Permission::findOrCreate('access-customer-support', 'user');
        $shop = ShopOwner::factory()->approved()->create();
        $other = ShopOwner::factory()->approved()->create();
        $crm = User::factory()->create(['id' => $other->id, 'shop_owner_id' => $shop->id, 'role' => 'CRM', 'status' => 'active']);
        $crm->givePermissionTo('access-customer-support');
        $this->clockInEmployee($crm);
        $customer = User::factory()->create();
        $review = ShopReview::create(['shop_owner_id' => $shop->id, 'user_id' => $customer->id, 'rating' => 1, 'comment' => 'Local']);
        $foreign = ShopReview::create(['shop_owner_id' => $other->id, 'user_id' => $customer->id, 'rating' => 1, 'comment' => 'Foreign']);
        $payload = ['review_id' => 'shop_'.$review->id, 'reason' => 'spam'];
        $this->actingAs($shop, 'shop_owner')->postJson('/api/shop-owner/reviews/report', $payload)->assertOk();
        ReviewReport::query()->sole()->update(['status' => 'dismissed']);
        $this->getJson('/api/shop-owner/reviews')->assertOk()->assertJsonPath('reviews.0.is_reported', true);
        $this->postJson('/api/shop-owner/reviews/report', $payload)->assertConflict();
        $this->actingAs($crm, 'user')->postJson('/api/crm/reviews/report', $payload)->assertConflict();
        $this->postJson('/api/crm/reviews/report', ['review_id' => 'shop_'.$foreign->id, 'reason' => 'spam'])->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/crm/reviews')->assertOk()->json('reviews.data'));
        $this->assertDatabaseCount('review_reports', 1);
    }
}
