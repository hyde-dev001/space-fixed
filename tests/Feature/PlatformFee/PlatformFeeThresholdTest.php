<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformFeeThresholdTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function threshold_notifications_are_recorded_only_on_crossing(): void
    {
        config()->set('platform_fee.shop_types.individual.balance_limit', '50.00');
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);

        $this->createMarketplaceOrder($shop, 1000);
        $this->assertDatabaseCount('platform_fee_threshold_states', 3);
        $this->assertDatabaseHas('platform_fee_threshold_states', [
            'shop_id' => $shop->id,
            'threshold_key' => 'limit',
            'crossed' => 1,
        ]);

        $firstNotificationCount = \App\Models\Notification::query()
            ->where('shop_owner_id', $shop->id)
            ->where('type', 'platform_balance_alert')
            ->count();

        $this->createMarketplaceOrder($shop, 1000);
        $this->assertSame(
            $firstNotificationCount,
            \App\Models\Notification::query()
                ->where('shop_owner_id', $shop->id)
                ->where('type', 'platform_balance_alert')
                ->count(),
        );
    }

    private function createMarketplaceOrder(ShopOwner $shop, int $total): void
    {
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => $total,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);
        $order->update(['status' => 'delivered']);
    }
}
