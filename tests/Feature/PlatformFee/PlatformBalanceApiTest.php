<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentAllocation;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlatformBalanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-dashboard', 'user');
    }

    #[Test]
    public function finance_can_read_only_its_shop_platform_balance_and_ledger(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $otherShop = ShopOwner::factory()->approved()->create(['registration_type' => 'business']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1000,
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $order->update(['status' => 'delivered']);

        Order::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 3000,
            'payment_status' => 'paid',
            'status' => 'delivered',
        ]);

        $response = $this->actingAs($finance, 'user')->getJson('/api/finance/platform-balance');

        $response->assertOk()
            ->assertJsonPath('balance.outstanding_balance', '56.00')
            ->assertJsonPath('balance.net_payable', '56.00')
            ->assertJsonPath('balance.shop_type', 'individual')
            ->assertJsonCount(1, 'charges');
        $this->assertSame($shop->id, $response->json('charges.0.shop_id'));
        $this->assertNotSame($otherShop->id, $response->json('charges.0.shop_id'));
    }

    #[Test]
    public function successful_refunds_hide_the_order_fee_from_the_ledger_without_deleting_audit_records(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');

        $refundedOrder = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1000,
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $refundedOrder->update(['status' => 'delivered']);
        $refundedCharge = $refundedOrder->platformFeeCharge;

        OrderRefund::create([
            'order_id' => $refundedOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'amount' => 250,
            'idempotency_key' => 'ledger-hidden-successful-refund',
        ]);

        $pendingOrder = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1000,
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $pendingOrder->update(['status' => 'delivered']);
        OrderRefund::create([
            'order_id' => $pendingOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
            'amount' => 1000,
            'idempotency_key' => 'ledger-pending-refund',
        ]);

        $response = $this->actingAs($finance, 'user')->getJson('/api/finance/platform-balance');
        $response->assertOk()->assertJsonCount(1, 'charges');
        $this->assertSame((int) $pendingOrder->platformFeeCharge->id, (int) $response->json('charges.0.id'));
        $this->assertDatabaseHas('platform_fee_charges', ['id' => $refundedCharge->id]);
        $this->assertDatabaseHas('order_refunds', [
            'order_id' => $refundedOrder->id,
            'status' => 'succeeded',
        ]);
    }

    #[Test]
    public function finance_read_initializes_missing_reliability_history_for_an_already_paid_business_fee(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);
        $charge = $order->platformFeeCharge;
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id,
            'amount' => $charge->total_charge,
            'balance_snapshot' => $charge->total_charge,
            'credit_snapshot' => '0.00',
            'net_payable_snapshot' => $charge->total_charge,
            'status' => 'paid',
            'idempotency_key' => 'existing-business-platform-payment',
            'paid_at' => now(),
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id,
            'platform_fee_charge_id' => $charge->id,
            'allocation_type' => 'payment',
            'amount' => $charge->total_charge,
            'idempotency_key' => 'existing-business-platform-payment-allocation',
        ]);

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/platform-balance')
            ->assertOk()
            ->assertJsonPath('balance.shop_type', 'business')
            ->assertJsonPath('reliability.latest.metrics.marketplace_orders', 1)
            ->assertJsonPath('reliability.latest.metrics.platform_fee_charges', 1)
            ->assertJsonPath('reliability.latest.metrics.paid_platform_fee_charges', 1);
    }

    #[Test]
    public function an_individual_shop_owner_can_open_the_platform_balance_page(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.platform-balance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/Finance/PlatformBalance', false));
    }
    private function chargeSource(ShopOwner $shop, string $type, string $reference): \App\Models\PlatformFeeCharge
    {
        config()->set('platform_fee.defaults.platform_fee_rate', '5');
        config()->set('platform_fee.defaults.platform_fee_vat_enabled', false);
        if ($type === 'order') {
            $source = Order::factory()->create([
                'shop_owner_id' => $shop->id, 'order_number' => $reference,
                'origin_channel' => 'marketplace', 'total_amount' => 6000,
                'payment_status' => 'paid', 'status' => 'completed',
            ]);
        } else {
            $source = \App\Models\RepairRequest::factory()->create([
                'shop_owner_id' => $shop->id, 'request_id' => $reference, 'origin_channel' => 'marketplace',
                'total' => 6000, 'final_total' => 6000, 'total_paid_amount' => 6000,
                'payment_status' => 'completed', 'status' => 'completed',
            ]);
        }
        return \App\Models\PlatformFeeCharge::where('source_type', $type)->where('source_id', $source->id)->firstOrFail();
    }

    #[Test]
    public function two_actual_300_charges_total_600_with_tenant_scoped_finance_and_owner_reads(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $other = ShopOwner::factory()->approved()->create();
        $this->chargeSource($shop, 'order', 'ORD-QA-300');
        $this->chargeSource($shop, 'repair', 'REP-QA-300');
        $this->chargeSource($other, 'order', 'ORD-OTHER-300');
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-dashboard');
        $this->actingAs($finance, 'user')->getJson('/api/finance/platform-balance')
            ->assertOk()->assertJsonPath('balance.outstanding_balance', '600.00')
            ->assertJsonPath('balance.net_payable', '600.00')->assertJsonCount(2, 'charges');
        $this->getJson('/api/finance/platform-balance?shop_id='.$other->id)->assertForbidden();
        $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/finance/platform-balance')
            ->assertOk()->assertJsonPath('balance.outstanding_balance', '600.00')->assertJsonCount(2, 'charges');
        $this->actingAs($other, 'shop_owner')->getJson('/api/shop-owner/finance/platform-balance')
            ->assertOk()->assertJsonPath('balance.outstanding_balance', '300.00')->assertJsonCount(1, 'charges');
        $otherFinance = User::factory()->create(['shop_owner_id' => $other->id]);
        $otherFinance->givePermissionTo('access-finance-dashboard');
        $this->actingAs($otherFinance, 'user')->getJson('/api/finance/platform-balance')
            ->assertOk()->assertJsonPath('balance.outstanding_balance', '300.00')->assertJsonCount(1, 'charges');
        $proxy = User::factory()->create(['id' => 1000, 'shop_owner_id' => $shop->id, 'role' => null]);
        ShopOwner::factory()->approved()->create(['id' => 1000]);
        $proxy->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Shop Owner', 'user'));
        $proxy->givePermissionTo('access-finance-dashboard');
        $this->actingAs($proxy, 'user')->getJson('/api/finance/platform-balance')
            ->assertOk()->assertJsonPath('balance.outstanding_balance', '600.00')->assertJsonCount(2, 'charges');

    }

    #[Test]
    public function charge_and_credit_references_use_snapshots_and_scoped_legacy_sources(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $orderCharge = $this->chargeSource($shop, 'order', 'ORD-SNAPSHOT');
        $repairCharge = $this->chargeSource($shop, 'repair', 'REP-LEGACY');
        $repairCharge->update(['metadata' => null]);
        Order::whereKey($orderCharge->source_id)->update(['order_number' => 'ORD-CHANGED']);
        \App\Models\PlatformCreditApplication::create([
            'shop_id' => $shop->id, 'platform_fee_charge_id' => $orderCharge->id, 'source_type' => 'order_refund',
            'source_id' => 700, 'source_origin' => 'marketplace', 'credit_amount' => 10,
            'status' => 'available', 'reason' => 'QA credit', 'idempotency_key' => 'qa-credit-reference',
        ]);
        $response = $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/finance/platform-balance')->assertOk();
        $rows = collect($response->json('charges'))->keyBy('id');
        $this->assertSame('ORD-SNAPSHOT', $rows[$orderCharge->id]['source_reference'] ?? null);
        $this->assertSame('REP-LEGACY', $rows[$repairCharge->id]['source_reference'] ?? null);
        $response->assertJsonPath('balance.credit_movements.0.source_reference', 'ORD-SNAPSHOT');
        $this->assertArrayNotHasKey('metadata', $rows[$orderCharge->id]);
    }

    #[Test]
    public function missing_metadata_never_resolves_a_source_or_credit_charge_from_another_shop(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $other = ShopOwner::factory()->approved()->create();
        $owned = $this->chargeSource($shop, 'order', 'ORD-OWN');
        $foreign = $this->chargeSource($other, 'order', 'ORD-SECRET');
        $foreignSource = Order::factory()->create(['shop_owner_id' => $other->id, 'order_number' => 'ORD-SECRET-UNFINALIZED', 'status' => 'pending']);
        $owned->update(['source_id' => $foreignSource->id, 'metadata' => null, 'source_origin' => 'marketplace']);
        \App\Models\PlatformCreditApplication::create([
            'shop_id' => $shop->id, 'platform_fee_charge_id' => $foreign->id, 'source_type' => 'order_refund',
            'source_id' => 701, 'source_origin' => 'marketplace', 'credit_amount' => 10,
            'status' => 'available', 'reason' => 'QA corrupt link', 'idempotency_key' => 'qa-corrupt-credit-reference',
        ]);
        $response = $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/finance/platform-balance')->assertOk();
        $response->assertJsonPath('charges.0.source_reference', 'Order #'.$foreignSource->id)
            ->assertJsonPath('balance.credit_movements.0.source_reference', 'Order refund #701');
        $this->assertStringNotContainsString('ORD-SECRET', $response->getContent());
    }

    #[Test]
    public function payment_applied_credit_and_void_reduce_the_600_pair_once(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $orderCharge = $this->chargeSource($shop, 'order', 'ORD-REDUCTIONS');
        $repairCharge = $this->chargeSource($shop, 'repair', 'REP-REDUCTIONS');
        $payment = PlatformFeePayment::create([
            'shop_id' => $shop->id, 'amount' => 100, 'balance_snapshot' => 600,
            'credit_snapshot' => 0, 'net_payable_snapshot' => 600, 'status' => 'paid',
            'idempotency_key' => 'qa-pair-payment',
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_fee_payment_id' => $payment->id, 'platform_fee_charge_id' => $orderCharge->id,
            'allocation_type' => 'payment', 'amount' => 100, 'idempotency_key' => 'qa-pair-payment-allocation',
        ]);
        $credit = \App\Models\PlatformCreditApplication::create([
            'shop_id' => $shop->id, 'platform_fee_charge_id' => $orderCharge->id, 'source_type' => 'order_refund',
            'source_id' => 702, 'source_origin' => 'marketplace', 'credit_amount' => 50,
            'status' => 'applied', 'reason' => 'QA applied credit', 'idempotency_key' => 'qa-pair-credit',
        ]);
        PlatformFeePaymentAllocation::create([
            'platform_credit_application_id' => $credit->id, 'platform_fee_charge_id' => $orderCharge->id,
            'allocation_type' => 'credit', 'amount' => 50, 'idempotency_key' => 'qa-pair-credit-allocation',
        ]);
        $repairCharge->update(['status' => 'void']);
        $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/finance/platform-balance')
            ->assertOk()->assertJsonPath('balance.outstanding_balance', '150.00')
            ->assertJsonPath('balance.available_credits', '0.00')->assertJsonPath('balance.net_payable', '150.00');
    }

    #[Test]
    public function detail_limit_does_not_truncate_balance_totals(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $base = $this->chargeSource($shop, 'order', 'ORD-DETAIL-LIMIT');
        for ($i = 1; $i <= 100; $i++) {
            $row = $base->replicate();
            $row->source_id = 100000 + $i;
            $row->save();
        }
        $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/finance/platform-balance')
            ->assertOk()->assertJsonCount(100, 'charges')
            ->assertJsonPath('balance.outstanding_balance', '30300.00');
    }

}
