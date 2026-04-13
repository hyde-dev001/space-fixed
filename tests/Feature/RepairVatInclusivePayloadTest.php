<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairVatInclusivePayloadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function my_repairs_payload_extracts_vat_when_tax_mode_is_vat_inclusive(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $repair = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-VAT-PAYLOAD-INC-001',
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $repair->id);
        $this->assertNotNull($row);
        $this->assertSame('vat_inclusive', (string) ($row['tax_mode'] ?? ''));
        $this->assertSame('1000.00', number_format((float) ($row['total_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('107.14', number_format((float) ($row['vat_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('1000.00', number_format((float) ($row['grand_total'] ?? 0), 2, '.', ''));
    }

    #[Test]
    public function my_repairs_payload_keeps_legacy_add_on_when_tax_mode_marker_is_missing(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $repair = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-VAT-PAYLOAD-LEG-001',
            'pricing_breakdown' => [],
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $repair->id);
        $this->assertNotNull($row);
        $this->assertSame('legacy_add_on', (string) ($row['tax_mode'] ?? ''));
        $this->assertSame('1000.00', number_format((float) ($row['total_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('120.00', number_format((float) ($row['vat_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('1120.00', number_format((float) ($row['grand_total'] ?? 0), 2, '.', ''));
    }

    #[Test]
    public function my_repairs_payload_reports_full_paid_amount_when_completed_with_mixed_channels(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $repair = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-VAT-MIXED-PAID-001',
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'completed',
            'total_paid_amount' => 0,
        ]);

        PosTransaction::create([
            'transaction_no' => 'POS-MIXED-PAID-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'balance',
            'subtotal' => 446.43,
            'tax_amount' => 53.57,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $repair->id);
        $this->assertNotNull($row);
        $this->assertSame('1000.00', number_format((float) ($row['total_paid_amount'] ?? 0), 2, '.', ''));
    }

    #[Test]
    public function my_repairs_payload_uses_parent_paid_amount_for_warranty_child_display(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $originalRepair = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-WAR-PARENT-001',
            'status' => 'picked_up',
            'payment_status' => 'completed',
            'total_paid_amount' => 1120,
        ]);

        $warrantyChild = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-WAR-CHILD-001',
            'status' => 'received',
            'total' => 0,
            'final_total' => 0,
            'payment_status' => 'completed',
            'total_paid_amount' => 0,
            'is_warranty_job' => true,
            'parent_repair_request_id' => $originalRepair->id,
            'billing_mode' => 'warranty_no_charge',
            'warranty_display_alias' => 'REP-WAR-PARENT-001-W1',
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $warrantyChild->id);
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row['is_warranty_job'] ?? false));
        $this->assertSame('0.00', number_format((float) ($row['total_paid_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('1120.00', number_format((float) ($row['display_total_paid_amount'] ?? 0), 2, '.', ''));
    }

    #[Test]
    public function retry_payment_session_uses_tax_mode_aware_due_amounts(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'paymongo_secret_key' => 'sk_test_tax_mode_aware',
        ]);
        $customer = User::factory()->create();

        $inclusiveRepair = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-VAT-RETRY-INC-001',
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_enabled' => true,
        ]);

        $legacyRepair = $this->createRepairForCustomer($shopOwner, $customer, [
            'request_id' => 'REP-VAT-RETRY-LEG-001',
            'pricing_breakdown' => [],
            'payment_enabled' => true,
        ]);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::sequence()
                ->push([
                    'data' => [
                        'id' => 'cs_inc_001',
                        'attributes' => [
                            'checkout_url' => 'https://pay.mongo/checkout/inc',
                        ],
                    ],
                ], 200)
                ->push([
                    'data' => [
                        'id' => 'cs_leg_001',
                        'attributes' => [
                            'checkout_url' => 'https://pay.mongo/checkout/leg',
                        ],
                    ],
                ], 200),
        ]);

        $inclusiveResponse = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$inclusiveRepair->id}/retry-payment-session");

        $inclusiveResponse->assertOk();
        $this->assertSame('vat_inclusive', (string) $inclusiveResponse->json('tax_mode'));
        $this->assertSame('446.43', number_format((float) $inclusiveResponse->json('subtotal_amount'), 2, '.', ''));
        $this->assertSame('53.57', number_format((float) $inclusiveResponse->json('vat_amount'), 2, '.', ''));
        $this->assertSame('500.00', number_format((float) $inclusiveResponse->json('total_amount'), 2, '.', ''));

        $legacyResponse = $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$legacyRepair->id}/retry-payment-session");

        $legacyResponse->assertOk();
        $this->assertSame('legacy_add_on', (string) $legacyResponse->json('tax_mode'));
        $this->assertSame('500.00', number_format((float) $legacyResponse->json('subtotal_amount'), 2, '.', ''));
        $this->assertSame('60.00', number_format((float) $legacyResponse->json('vat_amount'), 2, '.', ''));
        $this->assertSame('560.00', number_format((float) $legacyResponse->json('total_amount'), 2, '.', ''));

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && data_get($request->data(), 'data.attributes.line_items.0.amount') === 50000
                && str_contains((string) data_get($request->data(), 'data.attributes.description', ''), 'REP-VAT-RETRY-INC-001');
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && data_get($request->data(), 'data.attributes.line_items.0.amount') === 56000
                && str_contains((string) data_get($request->data(), 'data.attributes.description', ''), 'REP-VAT-RETRY-LEG-001');
        });
    }

    private function createRepairForCustomer(ShopOwner $shopOwner, User $customer, array $overrides = []): RepairRequest
    {
        return RepairRequest::create(array_merge([
            'request_id' => 'REP-VAT-BASE-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09179990000',
            'shoe_type' => 'Sneakers',
            'description' => 'VAT payload behavior test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => [],
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
            'payment_enabled' => false,
            'pricing_breakdown' => [],
        ], $overrides));
    }
}
