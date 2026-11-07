<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Services\PlatformRestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformRestrictionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_limit_crossing_restricts_marketplace_only(): void
    {
        config()->set('platform_fee.shop_types.individual.balance_limit', '50.00');

        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1000,
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);

        $restriction = app(PlatformRestrictionService::class);

        $summary = app(\App\Services\PlatformBalanceService::class)->summary($shop->id);
        $this->assertTrue($restriction->isRestricted($shop->id), json_encode($summary));
        $this->expectException(AuthorizationException::class);
        $restriction->assertMarketplaceAllowed($shop->id);
    }

    #[Test]
    public function individual_and_business_default_limits_are_resolved_separately(): void
    {
        $individual = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $business = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);

        Order::factory()->create([
            'shop_owner_id' => $individual->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 500000,
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);
        Order::factory()->create([
            'shop_owner_id' => $business->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 500000,
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);

        $restriction = app(PlatformRestrictionService::class);

        $this->assertSame('25000.00', app(\App\Services\PlatformBalanceService::class)->summary($individual->id)['balance_limit']);
        $this->assertSame('50000.00', app(\App\Services\PlatformBalanceService::class)->summary($business->id)['balance_limit']);
        $this->assertTrue($restriction->isRestricted($individual->id));
        $this->assertFalse($restriction->isRestricted($business->id));
    }
}
