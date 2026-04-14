<?php

namespace Tests\Feature\Reports;

use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\RepairReview;
use App\Models\ReviewReport;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopAndCustomerReportFlowTest extends TestCase
{
    use RefreshDatabase;

    private function extractInertiaPageData(string $html): array
    {
        preg_match('/data-page="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'Unable to locate Inertia data-page payload.');

        $decoded = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $page = json_decode($decoded, true);

        $this->assertIsArray($page, 'Unable to decode Inertia page payload.');

        return $page;
    }

    private function postWithCsrf(string $uri, array $payload = [])
    {
        $token = 'test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->post($uri, array_merge($payload, ['_token' => $token]));
    }

    private function createEligibleCustomer(): User
    {
        return User::factory()->create([
            'first_name' => 'Eligible',
            'last_name' => 'Customer',
        ]);
    }

    private function createDeliveredOrderFor(User $customer, ShopOwner $shopOwner): Order
    {
        return Order::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TST-' . uniqid(),
            'total_amount' => 999.99,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]);
    }

    public function test_authenticated_customer_can_submit_shop_report(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = $this->createEligibleCustomer();

        $this->createDeliveredOrderFor($customer, $shopOwner);

        $response = $this->actingAs($customer, 'user')
            ->postJson("/api/shops/{$shopOwner->id}/report", [
                'reason' => 'fraud',
                'description' => 'The transaction was suspicious and the delivered item did not match the listing details.',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Your report has been submitted and will be reviewed by our team.',
            ]);

        $this->assertDatabaseHas('shop_reports', [
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'reason' => 'fraud',
            'status' => 'submitted',
            'transaction_type' => 'order',
        ]);
    }

    public function test_new_customer_account_can_submit_shop_report_if_transaction_is_completed(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create([
            'first_name' => 'New',
            'last_name' => 'Customer',
            'created_at' => now(),
        ]);

        $this->createDeliveredOrderFor($customer, $shopOwner);

        $response = $this->actingAs($customer, 'user')
            ->postJson("/api/shops/{$shopOwner->id}/report", [
                'reason' => 'no_show',
                'description' => 'This is a valid report from a newly-created account with completed transaction proof.',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('shop_reports', [
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'submitted',
        ]);
    }

    public function test_customer_is_rate_limited_per_day_for_shop_reports(): void
    {
        config()->set('reporting.shop_report_daily_limit', 3);

        $customer = User::factory()->create();

        $shops = ShopOwner::factory()->approved()->count(4)->create();

        foreach ($shops->take(3) as $shopOwner) {
            $this->createDeliveredOrderFor($customer, $shopOwner);

            $this->actingAs($customer, 'user')
                ->postJson("/api/shops/{$shopOwner->id}/report", [
                    'reason' => 'other',
                    'description' => 'Daily limit test report with completed transaction proof for this shop owner.',
                ])
                ->assertStatus(201);
        }

        $blockedShop = $shops->get(3);
        $this->createDeliveredOrderFor($customer, $blockedShop);

        $blocked = $this->actingAs($customer, 'user')
            ->postJson("/api/shops/{$blockedShop->id}/report", [
                'reason' => 'other',
                'description' => 'This fourth report should be blocked by the daily rate limit.',
            ]);

        $blocked->assertStatus(429)
            ->assertJson([
                'message' => 'You have reached the daily report limit. Please try again tomorrow.',
            ]);

        $this->assertSame(3, ShopReport::where('user_id', $customer->id)->count());
    }

    public function test_authenticated_shop_owner_can_submit_customer_review_report(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();

        $repairRequest = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'completed',
        ]);

        $review = RepairReview::create([
            'repair_request_id' => $repairRequest->id,
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'rating' => 1,
            'review_text' => 'This review contains false claims and abusive language.',
            'is_verified' => true,
            'is_visible' => true,
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/api/shop-owner/reviews/report', [
                'review_id' => 'repair_' . $review->id,
                'reason' => 'spam',
                'notes' => 'Customer posted repeated abusive content with no factual basis.',
            ]);

        $response->assertOk()
            ->assertJsonPath('report.status', 'pending_review');

        $this->assertDatabaseHas('review_reports', [
            'review_type' => 'repair',
            'review_id' => $review->id,
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'reason' => 'spam',
            'status' => 'pending_review',
        ]);
    }

    public function test_super_admin_can_view_and_process_submitted_shop_and_review_reports(): void
    {
        $superAdmin = SuperAdmin::query()->firstOrFail();

        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = $this->createEligibleCustomer();

        $this->createDeliveredOrderFor($customer, $shopOwner);

        $this->actingAs($customer, 'user')
            ->postJson("/api/shops/{$shopOwner->id}/report", [
                'reason' => 'misconduct',
                'description' => 'Service behavior was inappropriate throughout the completed transaction and support was unhelpful.',
            ])
            ->assertStatus(201);

        $repairRequest = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'completed',
        ]);

        $review = RepairReview::create([
            'repair_request_id' => $repairRequest->id,
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'rating' => 1,
            'review_text' => 'Abusive and repeated content intended to damage reputation.',
            'is_verified' => true,
            'is_visible' => true,
        ]);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/api/shop-owner/reviews/report', [
                'review_id' => 'repair_' . $review->id,
                'reason' => 'harassment',
                'notes' => 'Multiple attacks in review text with no service-related detail.',
            ])
            ->assertOk();

        $this->actingAs($superAdmin, 'super_admin');

        $shopReportsPage = $this->get('/admin/shop-reports');
        $shopReportsPage->assertOk();

        $shopReportsPayload = $this->extractInertiaPageData($shopReportsPage->getContent());
        $this->assertSame('superAdmin/Shops/ShopReports', $shopReportsPayload['component'] ?? null);

        $shopGroups = collect($shopReportsPayload['props']['shopGroups'] ?? []);
        $this->assertTrue(
            $shopGroups->contains(fn ($group) => (int) ($group['shop_owner_id'] ?? 0) === $shopOwner->id),
            'Expected submitted shop report to be visible in super admin shop reports page.'
        );

        $this->postWithCsrf("/admin/shop-reports/{$shopOwner->id}/action", [
            'action' => 'warn',
            'admin_notes' => 'Reviewed and warned the shop owner based on submitted evidence.',
        ])->assertRedirect();

        $this->assertDatabaseHas('shop_reports', [
            'shop_owner_id' => $shopOwner->id,
            'status' => 'warned',
            'reviewed_by' => $superAdmin->id,
        ]);

        $flaggedPage = $this->get('/superAdmin/flagged-accounts');
        $flaggedPage->assertOk();

        $flaggedPayload = $this->extractInertiaPageData($flaggedPage->getContent());
        $this->assertSame('superAdmin/Users/FlaggedAccounts', $flaggedPayload['component'] ?? null);

        $flaggedAccounts = collect($flaggedPayload['props']['flaggedAccounts'] ?? []);
        $reviewReport = ReviewReport::query()->firstOrFail();

        $this->assertTrue(
            $flaggedAccounts->contains(fn ($entry) => (int) ($entry['id'] ?? 0) === $reviewReport->id),
            'Expected reported customer review to be visible in flagged accounts page.'
        );

        $this->postWithCsrf("/superAdmin/flagged-accounts/{$reviewReport->id}/mark-reviewed")
            ->assertOk()
            ->assertJson(['status' => 'under_investigation']);

        $this->postWithCsrf("/superAdmin/flagged-accounts/{$reviewReport->id}/dismiss", [
            'admin_notes' => 'Reviewed and dismissed after investigation.',
        ])->assertOk()->assertJson(['status' => 'dismissed']);

        $this->assertDatabaseHas('review_reports', [
            'id' => $reviewReport->id,
            'status' => 'dismissed',
        ]);
    }
}
