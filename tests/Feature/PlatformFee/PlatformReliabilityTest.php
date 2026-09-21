<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\PlatformFeeRecommendationService;
use App\Services\PlatformReliabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformReliabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pos_volume_does_not_change_the_marketplace_reliability_score(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $marketplace = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);
        $marketplace->update(['status' => 'completed']);

        $service = app(PlatformReliabilityService::class);
        $before = $service->recalculate($shop);

        Order::factory()->count(5)->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'pos',
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);

        $after = $service->recalculate($shop);

        $this->assertSame((string) $before->score, (string) $after->score);
        $this->assertSame(1, $after->metrics['marketplace_orders']);
        $this->assertSame(5, Order::query()->where('shop_owner_id', $shop->id)->where('origin_channel', 'pos')->count());
    }

    #[Test]
    public function score_history_is_daily_and_recommendations_wait_for_admin_approval(): void
    {
        config()->set('platform_fee.reliability.tiers', [
            ['key' => 'base', 'minimum_score' => 0, 'recommended_limit' => '30000.00'],
        ]);
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $service = app(PlatformReliabilityService::class);
        $service->recalculate($shop, now()->subDay());
        $service->recalculate($shop, now());

        $recommendation = app(PlatformFeeRecommendationService::class)->recommend($shop);

        $this->assertNotNull($recommendation);
        $this->assertSame('pending', $recommendation->status);
        $this->assertSame(2, $shop->reliabilityScores()->count());

        $approved = app(PlatformFeeRecommendationService::class)->approve($recommendation, 99, 'Approved for sustained history.');

        $this->assertSame('approved', $approved->status);
        $this->assertSame('30000.00', app(\App\Services\PlatformFeeSettingsResolver::class)->forShop($shop)['balance_limit']);
        $this->assertDatabaseCount('platform_fee_recommendations', 1);
    }

    #[Test]
    public function one_legitimate_refund_does_not_collapse_a_new_shop_score(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 100,
            'idempotency_key' => 'reliability-legitimate-refund-1',
        ]);

        $score = app(PlatformReliabilityService::class)->recalculate($shop);

        $this->assertGreaterThan(0, (float) data_get($score->factor_breakdown, 'refund_performance.score'));
    }
}
