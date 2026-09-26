<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Notification;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentAllocation;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Enums\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class PlatformFeeMoneyMovementTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-dashboard', 'user');
    }

    #[Test]
    public function owner_and_finance_can_see_when_credit_reduced_their_outstanding_balance(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $paidOrder = $this->createMarketplaceOrder($shop, 1000);
        $this->markChargePaid($shop, $paidOrder, 'money-movement-payment');

        $refund = OrderRefund::create([
            'order_id' => $paidOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'money-movement-refund',
        ]);
        $this->createMarketplaceOrder($shop, 1500);

        $assertMovement = function (array $json) use ($refund): void {
            $this->assertSame('56.00', data_get($json, 'balance.credit_summary.issued'));
            $this->assertSame('56.00', data_get($json, 'balance.credit_summary.applied'));
            $this->assertSame('0.00', data_get($json, 'balance.credit_summary.remaining'));
            $this->assertSame('84.00', data_get($json, 'balance.credit_summary.outstanding_before_credits'));
            $this->assertSame('28.00', data_get($json, 'balance.credit_summary.outstanding_after_credits'));
            $this->assertSame('56.00', data_get($json, 'balance.credit_summary.outstanding_reduced_by_credits'));
            $this->assertSame('28.00', data_get($json, 'balance.outstanding_balance'));
            $this->assertSame('56.00', data_get($json, 'balance.credit_movements.0.credit_amount'));
            $this->assertSame('56.00', data_get($json, 'balance.credit_movements.0.applied_amount'));
            $this->assertSame('0.00', data_get($json, 'balance.credit_movements.0.remaining_amount'));
            $this->assertSame($refund->id, data_get($json, 'balance.credit_movements.0.source_id'));
            $this->assertSame('84.00', data_get($json, 'balance.credit_movements.0.outstanding_before_credit'));
            $this->assertSame('28.00', data_get($json, 'balance.credit_movements.0.outstanding_after_credit'));
        };

        $ownerPayload = $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/finance/platform-balance')
            ->assertOk()
            ->json();
        $assertMovement($ownerPayload);

        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $this->clockInEmployee($finance);

        $financePayload = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/platform-balance')
            ->assertOk()
            ->json();
        $assertMovement($financePayload);
    }

    #[Test]
    public function credit_application_notifies_the_shop_owner_and_business_finance(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_name' => 'Credit Notification Shop',
        ]);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $this->clockInEmployee($finance);

        $paidOrder = $this->createMarketplaceOrder($shop, 1000);
        $this->markChargePaid($shop, $paidOrder, 'credit-notification-payment');

        $refund = OrderRefund::create([
            'order_id' => $paidOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'credit-notification-refund',
        ]);
        $this->createMarketplaceOrder($shop, 1500);

        $ownerNotification = Notification::query()
            ->where('shop_owner_id', $shop->id)
            ->where('title', 'Platform Credit Applied')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(NotificationType::PLATFORM_BALANCE_ALERT, $ownerNotification->type);
        $this->assertSame('56.00', $ownerNotification->data['applied_amount']);
        $this->assertSame($refund->id, $ownerNotification->data['source_id']);
        $this->assertSame('28.00', $ownerNotification->data['outstanding_balance']);

        $financeNotification = Notification::query()
            ->where('user_id', $finance->id)
            ->where('title', 'Platform Credit Applied')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('56.00', $financeNotification->data['applied_amount']);
        $this->assertStringContainsString('outstanding Platform Balance', $financeNotification->message);
    }

    #[Test]
    public function admin_can_see_credit_movement_separately_from_earned_total(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $shop = ShopOwner::factory()->approved()->create(['business_name' => 'Credit Movement Shop']);
        $paidOrder = $this->createMarketplaceOrder($shop, 1000);
        $this->markChargePaid($shop, $paidOrder, 'admin-money-movement-payment');

        OrderRefund::create([
            'order_id' => $paidOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'idempotency_key' => 'admin-money-movement-refund',
        ]);
        $this->createMarketplaceOrder($shop, 1500);

            $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/platform-fees')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('metrics.platform_fee_earned', '125.00')
                ->where('shops.0.name', 'Credit Movement Shop')
                ->where('shops.0.credit_summary.issued', '56.00')
                ->where('shops.0.credit_summary.applied', '56.00')
                ->where('shops.0.credit_summary.remaining', '0.00')
                ->where('shops.0.latest_credit_movement.applied_amount', '56.00'));
    }

    private function createMarketplaceOrder(ShopOwner $shop, int $total): Order
    {
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => $total,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);
        $order->update(['status' => 'delivered']);

        return $order->fresh('platformFeeCharge');
    }

    private function markChargePaid(ShopOwner $shop, Order $order, string $key): void
    {
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id,
            'amount' => $order->platformFeeCharge->total_charge,
            'balance_snapshot' => $order->platformFeeCharge->total_charge,
            'credit_snapshot' => '0.00',
            'net_payable_snapshot' => $order->platformFeeCharge->total_charge,
            'status' => 'paid',
            'idempotency_key' => $key,
            'paid_at' => now(),
        ]);

        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id,
            'platform_fee_charge_id' => $order->platformFeeCharge->id,
            'allocation_type' => 'payment',
            'amount' => $order->platformFeeCharge->total_charge,
            'idempotency_key' => $key.'-allocation',
        ]);
    }
}
