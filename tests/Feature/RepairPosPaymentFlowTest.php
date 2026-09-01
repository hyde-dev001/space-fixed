<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repair_pos_requires_name_and_phone_for_every_payment_method(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $actor = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        foreach ([
            ['cash', null],
            ['paymongo_wallet', 'GCASH-REPAIR-001'],
            ['paymongo_card', 'CARD-REPAIR-001'],
        ] as [$tenderType, $providerReference]) {
            $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
                'repair_request_id' => null,
                'due_type' => 'deposit',
                'customer_type' => 'walk_in',
                'walk_in_name' => null,
                'walk_in_phone' => null,
                'idempotency_key' => 'repair-customer-required-' . $tenderType,
                'manual_repair_subtotal' => 1000,
                'manual_service_summary' => 'Customer requirement regression',
                'payment_lines' => [[
                    'tender_type' => $tenderType,
                    'amount' => 500,
                    'provider_reference' => $providerReference,
                ]],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['walk_in_name', 'walk_in_phone']);
        }

        $this->assertDatabaseCount('repair_requests', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    #[Test]
    public function repair_with_missing_canonical_phone_cannot_use_submitted_fallback(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $actor = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);
        $customer = \App\Models\User::factory()->create([
            'phone' => null,
        ]);
        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-MISSING-CANONICAL-PHONE-001',
            'customer_name' => 'Legacy Repair Customer',
            'email' => 'N/A',
            'phone' => '',
            'shoe_type' => 'Sneakers',
            'description' => 'Missing canonical phone regression',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => [],
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
            'intake_delivery_method' => 'walk_in',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'walk_in_name' => 'Submitted Fallback Name',
            'walk_in_phone' => '09171234567',
            'idempotency_key' => 'repair-canonical-phone-001',
            'payment_lines' => [[
                'tender_type' => 'cash',
                'amount' => 500,
            ]],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['walk_in_phone']);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    #[Test]
    public function shop_owner_guard_can_checkout_walk_in_without_unauthorized_response(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk-in From Shop Owner Guard',
            'walk_in_phone' => '09179990000',
            'idempotency_key' => 'shop-owner-guard-checkout-001',
            'manual_repair_subtotal' => 800,
            'manual_service_summary' => 'Walk-in guard regression coverage',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 400],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $transactionId = (int) $response->json('transaction_id');
        $this->assertGreaterThan(0, $transactionId);

        $transaction = \App\Models\PosTransaction::query()->findOrFail($transactionId);
        $this->assertSame((int) $shopOwner->id, (int) $transaction->shop_owner_id);
        $this->assertSame('walk_in', (string) $transaction->customer_type);
        $this->assertSame('deposit', (string) $transaction->due_type);
    }

    #[Test]
    public function shop_owner_guard_can_checkout_owner_approved_job_order(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-SO-JO-OWNER-APPROVED-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09179997777',
            'shoe_type' => 'Sneakers',
            'description' => 'Owner approved checkout regression test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => [],
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'owner_approved',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'shop-owner-owner-approved-checkout-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    #[Test]
    public function manual_pos_walk_in_repair_cannot_activate_payment(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $checkoutResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk-in Activation Guard',
            'walk_in_phone' => '09179991111',
            'idempotency_key' => 'shop-owner-walkin-activate-guard-001',
            'manual_repair_subtotal' => 900,
            'manual_service_summary' => 'Walk-in activation guard regression',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 450],
            ],
        ]);

        $checkoutResponse->assertOk()->assertJsonPath('success', true);

        $transactionId = (int) $checkoutResponse->json('transaction_id');
        $this->assertGreaterThan(0, $transactionId);

        $transaction = \App\Models\PosTransaction::query()->findOrFail($transactionId);
        $repairId = (int) $transaction->module_reference_id;

        $activationResponse = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repairId}/activate-payment");

        $activationResponse->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'POS_WALKIN_ACTIVATION_NOT_REQUIRED');
    }

    #[Test]
    public function shop_owner_can_activate_remaining_balance_when_payment_status_is_partially_paid(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-REM-PARTIAL-001',
            'customer_name' => 'Remaining Balance Customer',
            'email' => 'remaining-balance@example.test',
            'phone' => '09178889999',
            'shoe_type' => 'Sneakers',
            'description' => 'Remaining balance activation regression',
            'shop_owner_id' => $shopOwner->id,
            'status' => 'ready_for_pickup',
            'delivery_method' => 'pickup',
            'return_delivery_method' => 'customer_pickup',
            'total' => 1000,
            'final_total' => 1000,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'partially_paid',
            'payment_enabled' => false,
            'images' => [],
        ]);

        $activationResponse = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/activate-payment");

        $activationResponse->assertOk()
            ->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertTrue((bool) $repair->payment_enabled);
    }

    #[Test]
    public function walk_in_checkout_without_repair_request_creates_manual_repair_reference_and_receipt(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Manual Walk-in Customer',
            'walk_in_phone' => '09171234567',
            'idempotency_key' => 'manual-walkin-deposit-001',
            'manual_repair_subtotal' => 599,
            'manual_service_summary' => 'Starter Clean Package (2 services)',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 299.50],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $transactionId = (int) $response->json('transaction_id');
        $this->assertGreaterThan(0, $transactionId);

        $transaction = \App\Models\PosTransaction::query()->findOrFail($transactionId);
        $this->assertSame('repair', (string) $transaction->module_type);
        $this->assertSame('walk_in', (string) $transaction->customer_type);
        $this->assertSame('deposit', (string) $transaction->due_type);
        $this->assertSame('299.50', number_format((float) $transaction->total_amount, 2, '.', ''));

        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);
        $this->assertSame((int) $shopOwner->id, (int) $repair->shop_owner_id);
        $this->assertSame('Manual Walk-in Customer', (string) $repair->customer_name);
        $this->assertSame('N/A', (string) $repair->email);
        $this->assertSame('deposit_50', (string) $repair->payment_policy_snapshot);
        $this->assertSame('paid', (string) $repair->payment_status_derived);

        $receipt = \App\Models\PosReceipt::query()->where('pos_transaction_id', $transaction->id)->first();
        $this->assertNotNull($receipt);
    }

    #[Test]
    public function manual_pos_package_add_ons_persist_after_ready_for_pickup_transition(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $includedService = \App\Models\RepairService::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Clean Service',
            'category' => 'Cleaning',
            'price' => 599,
            'duration' => '40 min',
            'status' => 'Active',
        ]);

        $addOnService = \App\Models\RepairService::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Whitening Add-on',
            'category' => 'Enhancement',
            'price' => 450,
            'duration' => '30 min',
            'status' => 'Active',
        ]);

        $package = \App\Models\RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Clean Package',
            'description' => 'POS package + add-on regression test',
            'package_price' => 599,
            'status' => 'active',
        ]);
        $package->services()->sync([$includedService->id]);

        $checkoutResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'POS Package Add-on Customer',
            'walk_in_phone' => '09178887777',
            'idempotency_key' => 'manual-pos-package-addon-001',
            'manual_repair_subtotal' => 1049,
            'manual_service_summary' => 'Starter package with whitening add-on',
            'manual_payment_policy' => 'deposit_50',
            'manual_repair_package_id' => $package->id,
            'manual_service_ids' => [$includedService->id, $addOnService->id],
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 524.50],
            ],
        ]);

        $checkoutResponse->assertOk()->assertJsonPath('success', true);

        $transactionId = (int) $checkoutResponse->json('transaction_id');
        $transaction = \App\Models\PosTransaction::query()->findOrFail($transactionId);
        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);

        $this->assertSame((int) $package->id, (int) $repair->repair_package_id);
        $this->assertSame('599.00', number_format((float) $repair->package_price, 2, '.', ''));
        $this->assertSame('450.00', number_format((float) $repair->add_ons_total, 2, '.', ''));
        $this->assertSame('1049.00', number_format((float) $repair->total, 2, '.', ''));
        $this->assertSame('1049.00', number_format((float) $repair->final_total, 2, '.', ''));
        $this->assertCount(1, (array) $repair->add_on_services_snapshot);

        $repair->update(['status' => 'in_progress']);

        $markReadyResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson(
            "/api/shop-owner/repairs/{$repair->id}/mark-ready",
            [
                'pickup_instructions' => 'Ready for pickup.',
                'no_materials_used_confirmed' => true,
                'variance_override_confirmed' => true,
            ]
        );

        $markReadyResponse->assertOk()->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('ready_for_pickup', (string) $repair->status);
        $this->assertSame('450.00', number_format((float) $repair->add_ons_total, 2, '.', ''));
        $this->assertSame('1049.00', number_format((float) $repair->final_total, 2, '.', ''));
        $this->assertSame('1049.00', number_format((float) data_get($repair->pricing_breakdown, 'base_total', 0), 2, '.', ''));
    }

    #[Test]
    public function balance_checkout_uses_original_manual_pos_total_even_if_repair_totals_were_downgraded(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $includedService = \App\Models\RepairService::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Clean Service',
            'category' => 'Cleaning',
            'price' => 599,
            'duration' => '40 min',
            'status' => 'Active',
        ]);

        $addOnService = \App\Models\RepairService::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Whitening Add-on',
            'category' => 'Enhancement',
            'price' => 450,
            'duration' => '30 min',
            'status' => 'Active',
        ]);

        $package = \App\Models\RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Clean Package',
            'description' => 'Balance due regression test',
            'package_price' => 599,
            'status' => 'active',
        ]);
        $package->services()->sync([$includedService->id]);

        $depositResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'POS Remaining Balance Customer',
            'walk_in_phone' => '09179996666',
            'idempotency_key' => 'manual-pos-balance-regression-deposit-001',
            'manual_repair_subtotal' => 1049,
            'manual_service_summary' => 'Starter package with whitening add-on',
            'manual_payment_policy' => 'deposit_50',
            'manual_repair_package_id' => $package->id,
            'manual_service_ids' => [$includedService->id, $addOnService->id],
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 524.50],
            ],
        ]);

        $depositResponse->assertOk()->assertJsonPath('success', true);

        $depositTransaction = \App\Models\PosTransaction::query()->findOrFail((int) $depositResponse->json('transaction_id'));
        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $depositTransaction->module_reference_id);

        // Simulate stale data issue where totals were downgraded to package-only after workflow updates.
        $repair->forceFill([
            'total' => 599,
            'final_total' => 599,
            'add_ons_total' => 0,
            'status' => 'ready_for_pickup',
            'pricing_breakdown' => array_merge((array) ($repair->pricing_breakdown ?? []), [
                'mode' => 'manual_pos',
                'base_total' => 599,
                'final_total' => 599,
                'package_price' => 599,
                'add_ons_total' => 0,
            ]),
        ])->save();

        $balanceResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'POS Remaining Balance Customer',
            'walk_in_phone' => '09179996666',
            'idempotency_key' => 'manual-pos-balance-regression-balance-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 524.50],
            ],
        ]);

        $balanceResponse->assertOk()->assertJsonPath('success', true);

        $balanceTransaction = \App\Models\PosTransaction::query()->findOrFail((int) $balanceResponse->json('transaction_id'));
        $this->assertSame('524.50', number_format((float) $balanceTransaction->total_amount, 2, '.', ''));
        $this->assertSame('balance', (string) $balanceTransaction->due_type);
    }

    #[Test]
    public function cashier_walk_in_payment_is_visible_in_repairer_job_orders_with_paid_amount(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'company',
        ]);

        $cashierRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'user',
        ]);

        $repairerRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Repairer',
            'guard_name' => 'user',
        ]);

        /** @var \App\Models\User $cashier */
        $cashier = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $cashier->assignRole($cashierRole);

        /** @var \App\Models\User $repairer */
        $repairer = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairer->assignRole($repairerRole);

        $checkout = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Cashier Walk-in Customer',
            'walk_in_phone' => '09173330000',
            'idempotency_key' => 'cashier-job-order-visibility-001',
            'manual_repair_subtotal' => 1000,
            'manual_service_summary' => 'Walk-in service from cashier',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $checkout->assertOk()->assertJsonPath('success', true);

        $transaction = \App\Models\PosTransaction::query()->findOrFail((int) $checkout->json('transaction_id'));
        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);

        $this->assertNotNull($repair->assigned_repairer_id);
        $this->assertSame((int) $repairer->id, (int) $repair->assigned_repairer_id);

        $jobOrders = $this->actingAs($repairer, 'user')->getJson('/api/repairer/repairs');
        $jobOrders->assertOk()->assertJsonPath('success', true);

        $entry = collect($jobOrders->json('data'))->firstWhere('id', $repair->id);
        $this->assertNotNull($entry);
        $this->assertSame((string) $repair->request_id, (string) ($entry['request_id'] ?? ''));
        $this->assertSame('paid', strtolower((string) ($entry['payment_status'] ?? '')));
        $this->assertSame('500.00', number_format((float) ($entry['total_paid_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('vat_inclusive', (string) ($entry['tax_mode'] ?? ''));
        $this->assertSame('vat_inclusive', (string) data_get($entry, 'pricing_breakdown.tax_mode', ''));
    }

    #[Test]
    public function repairer_repairs_endpoint_uses_user_scope_when_shop_owner_session_also_exists(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'company',
        ]);

        $repairerRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Repairer',
            'guard_name' => 'user',
        ]);

        /** @var \App\Models\User $repairer */
        $repairer = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairer->assignRole($repairerRole);

        $assignedRepair = \App\Models\RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'assigned_to_repairer',
            'request_id' => 'REP-GUARD-CONTEXT-ASSIGNED-001',
        ]);

        $unassignedNewRequest = \App\Models\RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'assigned_repairer_id' => null,
            'status' => 'new_request',
            'request_id' => 'REP-GUARD-CONTEXT-NEW-001',
        ]);

        // Simulate a browser session where both guards are authenticated.
        $this->actingAs($repairer, 'user');
        $this->actingAs($shopOwner, 'shop_owner');

        $response = $this->getJson('/api/repairer/repairs');
        $response->assertOk()->assertJsonPath('success', true);

        $repairIds = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertTrue($repairIds->contains((int) $assignedRepair->id));
        $this->assertFalse($repairIds->contains((int) $unassignedNewRequest->id));
    }

    #[Test]
    public function repairer_can_accept_or_reject_manual_pos_walk_in_without_customer_account(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'company',
        ]);

        $cashierRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'user',
        ]);

        $repairerRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Repairer',
            'guard_name' => 'user',
        ]);

        /** @var \App\Models\User $cashier */
        $cashier = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $cashier->assignRole($cashierRole);

        /** @var \App\Models\User $repairer */
        $repairer = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairer->assignRole($repairerRole);

        $checkout = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk-in No Account',
            'walk_in_phone' => '09174445555',
            'idempotency_key' => 'manual-pos-accept-no-account-001',
            'manual_repair_subtotal' => 1000,
            'manual_service_summary' => 'No-account acceptance test',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $checkout->assertOk()->assertJsonPath('success', true);

        $transaction = \App\Models\PosTransaction::query()->findOrFail((int) $checkout->json('transaction_id'));
        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);

        $this->assertNull($repair->user_id);
        $this->assertSame((int) $repairer->id, (int) $repair->assigned_repairer_id);
        $this->assertSame('new_request', (string) $repair->status);

        $acceptResponse = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/accept");

        $acceptResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation_id', null);

        $repair->refresh();
        $this->assertSame('pending', (string) $repair->status);
        $this->assertNull($repair->conversation_id);
        $this->assertDatabaseCount('conversations', 0);

        $rejectCheckout = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk-in Rejection Candidate',
            'walk_in_phone' => '09174446666',
            'idempotency_key' => 'manual-pos-reject-no-account-001',
            'manual_repair_subtotal' => 800,
            'manual_service_summary' => 'No-account rejection test',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 400],
            ],
        ]);

        $rejectCheckout->assertOk()->assertJsonPath('success', true);
        $rejectTransaction = \App\Models\PosTransaction::query()->findOrFail((int) $rejectCheckout->json('transaction_id'));
        $rejectRepair = \App\Models\RepairRequest::query()->findOrFail((int) $rejectTransaction->module_reference_id);
        $this->assertSame('new_request', (string) $rejectRepair->status);

        $rejectResponse = $this->actingAs($repairer, 'user')
            ->postJson('/api/repairer/repairs/' . $rejectRepair->id . '/reject', [
                'reason_category' => 'skills_gap',
                'reason_text' => 'This service requires skills that are not available today.',
            ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('repair.status', 'repairer_rejected');
    }

    #[Test]
    public function manager_rejection_notifies_cashier_without_auto_refund_and_cashier_records_manual_pos_refund(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'company',
            'require_two_way_approval' => false,
        ]);

        $cashierRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'user',
        ]);
        $repairerRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Repairer',
            'guard_name' => 'user',
        ]);
        $managerRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Manager',
            'guard_name' => 'user',
        ]);
        $financeRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Finance',
            'guard_name' => 'user',
        ]);

        $cashier = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $cashier->assignRole($cashierRole);

        $repairer = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairer->assignRole($repairerRole);

        $manager = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'role' => 'Manager',
            'status' => 'active',
        ]);
        $manager->assignRole($managerRole);

        $finance = \App\Models\User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $finance->assignRole($financeRole);

        $checkout = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'full',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Rejected No Account Customer',
            'walk_in_phone' => '09174447777',
            'idempotency_key' => 'manual-pos-manager-reject-refund-001',
            'manual_repair_subtotal' => 1000,
            'manual_service_summary' => 'No-account manager rejection refund test',
            'manual_payment_policy' => 'full_upfront',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1000],
            ],
        ]);

        $checkout->assertOk()->assertJsonPath('success', true);

        $transaction = \App\Models\PosTransaction::query()->findOrFail((int) $checkout->json('transaction_id'));
        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);
        $this->assertNull($transaction->customer_id);
        $this->assertNull($repair->user_id);
        $this->assertSame('new_request', (string) $repair->status);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/reject", [
                'reason_category' => 'skills_gap',
                'reason_text' => 'This service cannot be completed by the assigned repairer.',
            ])
            ->assertOk()
            ->assertJsonPath('repair.status', 'repairer_rejected');

        $this->actingAs($manager, 'user')
            ->postJson("/api/manager/repairs/{$repair->id}/final-reject", [
                'reason' => 'Manager confirmed the repairer rejection.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('rejected', (string) $repair->status);
        $this->assertSame('approve_rejection', (string) $repair->manager_decision);
        $this->assertDatabaseCount('pos_refunds', 0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $cashier->id,
            'shop_id' => $shopOwner->id,
            'type' => 'refund_request',
            'group_key' => 'repair-manual-refund-' . $repair->id,
            'requires_action' => 1,
            'action_url' => '/erp/cashier/point-of-sale',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $finance->id,
            'type' => 'refund_request',
        ]);

        $receipt = \App\Models\PosReceipt::query()
            ->where('pos_transaction_id', $transaction->id)
            ->firstOrFail();

        $this->actingAs($manager, 'user')
            ->postJson('/api/repair-pos/refunds/manual-rejected-no-account', [
                'source_transaction_id' => $transaction->id,
                'receipt_no' => $receipt->receipt_no,
            ])
            ->assertForbidden();

        $manualRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/repair-pos/refunds/manual-rejected-no-account', [
                'source_transaction_id' => $transaction->id,
                'receipt_no' => $receipt->receipt_no,
            ]);

        $manualRefund->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'succeeded');

        $refundId = (int) $manualRefund->json('refund_id');
        $this->assertDatabaseHas('pos_refunds', [
            'id' => $refundId,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'workflow_source' => 'manager_rejected_no_account_pos',
            'status' => 'succeeded',
            'finance_status' => 'approved',
            'shop_owner_status' => 'skipped',
            'approved_amount' => 1000,
            'executed_by' => $cashier->id,
        ]);
        $this->assertDatabaseCount('pos_refunds', 1);

        $repair->refresh();
        $transaction->refresh();
        $this->assertSame('refunded', (string) $repair->payment_status);
        $this->assertSame('refunded', (string) $transaction->status);

        $replay = $this->actingAs($cashier, 'user')
            ->postJson('/api/repair-pos/refunds/manual-rejected-no-account', [
                'source_transaction_id' => $transaction->id,
                'receipt_no' => $receipt->receipt_no,
            ]);

        $replay->assertOk()
            ->assertJsonPath('refund_id', $refundId)
            ->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseCount('pos_refunds', 1);
    }

    #[Test]
    public function deposit_due_uses_inclusive_split_and_extracts_vat(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-VAT-INC-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09175550000',
            'shoe_type' => 'Sneakers',
            'description' => 'Inclusive VAT test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'vat-inclusive-pos-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertOk();

        $transaction = \App\Models\PosTransaction::query()->findOrFail((int) $response->json('transaction_id'));

        $this->assertSame('500.00', number_format((float) $transaction->total_amount, 2, '.', ''));
        $this->assertSame('53.57', number_format((float) $transaction->tax_amount, 2, '.', ''));
        $this->assertSame('446.43', number_format((float) $transaction->subtotal, 2, '.', ''));
        $this->assertSame('vat_inclusive', (string) data_get($transaction->metadata, 'tax_mode'));
    }

    #[Test]
    public function checkout_replay_returns_existing_transaction_and_does_not_duplicate_phase_charge(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-IDEM-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000111',
            'shoe_type' => 'Sneakers',
            'description' => 'Idempotency replay test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $payload = [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'idem-phase-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ];

        $first = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $payload);
        $second = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200)->assertJsonPath('meta.idempotency_replay', true);

        $this->assertSame(1, \App\Models\PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->where('due_type', 'deposit')
            ->count());
    }

    #[Test]
    public function receipt_includes_registered_customer_identity_fields(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create([
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'email' => 'jamie@example.com',
            'shop_owner_id' => null,
        ]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-RCPT-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09175555555',
            'shoe_type' => 'Sneakers',
            'description' => 'Receipt customer identity test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $transaction = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RCPT-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'deposit',
            'subtotal' => 446.43,
            'tax_amount' => 53.57,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        app(\App\Services\RepairPosReceiptService::class)->issue($transaction->fresh('paymentLines'));

        $receipt = \App\Models\PosReceipt::query()->where('pos_transaction_id', $transaction->id)->firstOrFail();
        $payload = $receipt->print_payload;

        $this->assertSame('Jamie Santos', data_get($payload, 'customer.name'));
        $this->assertSame('jamie@example.com', data_get($payload, 'customer.email'));
    }

    #[Test]
    public function payment_and_refund_transitions_keep_payment_status_canonical_and_in_sync(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-CANON-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09174444444',
            'shoe_type' => 'Sneakers',
            'description' => 'Canonical status sync test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'canon-sync-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ])->assertOk();

        $repair->refresh();

        $this->assertSame((string) $repair->payment_status_derived, (string) $repair->payment_status);
        $this->assertContains((string) $repair->payment_status, ['unpaid', 'partially_paid', 'paid', 'partially_refunded', 'refunded']);
    }

    #[Test]
    public function mixed_online_then_pos_payment_preserves_total_paid_amount_and_job_order_payload(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-MIXED-PAY-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09173333333',
            'shoe_type' => 'Sneakers',
            'description' => 'Mixed online then POS payment aggregation test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 500,
            'paymongo_payment_id' => 'pay_online_first_001',
        ]);

        $checkoutResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'mixed-online-pos-balance-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $checkoutResponse->assertOk()->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('1000.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
        $this->assertSame('completed', (string) $repair->payment_status);
        $this->assertSame('pay_online_first_001', (string) $repair->paymongo_payment_id);

        $jobOrdersResponse = $this->actingAs($shopOwner, 'shop_owner')->getJson('/api/shop-owner/repairs');
        $jobOrdersResponse->assertOk()->assertJsonPath('success', true);

        $jobOrderPayload = collect($jobOrdersResponse->json('data'))
            ->firstWhere('id', $repair->id);

        $this->assertNotNull($jobOrderPayload);
        $this->assertSame('pay_online_first_001', (string) ($jobOrderPayload['paymongo_payment_id'] ?? ''));
        $this->assertEqualsWithDelta(1000.00, (float) ($jobOrderPayload['total_paid_amount'] ?? 0), 0.01);
    }

    #[Test]
    public function online_settlement_updates_total_paid_amount_for_deposit_and_remaining_balance(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-ONLINE-PHASES-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000077',
            'shoe_type' => 'Sneakers',
            'description' => 'Online settlement should persist paid totals per phase',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
            'total_paid_amount' => 0,
        ]);

        $settlementService = app(\App\Services\PaymentSettlementService::class);

        $firstSettlement = $settlementService->settleRepairPaid($repair->fresh(), 'pay_online_deposit_001', true);
        $this->assertSame('settled', $firstSettlement['result']);

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status);
        $this->assertSame('500.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));

        $repair->update(['status' => 'ready_for_pickup']);

        $secondSettlement = $settlementService->settleRepairPaid($repair->fresh(), 'pay_online_balance_001', true);
        $this->assertSame('settled', $secondSettlement['result']);

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status);
        $this->assertSame('1000.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function job_order_payload_reconciles_completed_repairs_with_stale_paid_amount(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-JOBORDER-RECON-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000078',
            'shoe_type' => 'Sneakers',
            'description' => 'Job order payload reconciliation for stale mixed payment totals',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'completed',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'completed',
            'total_paid_amount' => 500,
            'paymongo_payment_id' => 'pay_stale_mix_001',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')->getJson('/api/shop-owner/repairs');
        $response->assertOk()->assertJsonPath('success', true);

        $jobOrderPayload = collect($response->json('data'))
            ->firstWhere('id', $repair->id);

        $this->assertNotNull($jobOrderPayload);
        $this->assertEqualsWithDelta(1000.00, (float) ($jobOrderPayload['total_paid_amount'] ?? 0), 0.01);
    }

    #[Test]
    public function pos_ledger_tables_exist_for_repair_module(): void
    {
        $this->assertTrue(Schema::hasTable('pos_transactions'));
        $this->assertTrue(Schema::hasTable('pos_payment_lines'));
        $this->assertTrue(Schema::hasTable('pos_receipts'));
        $this->assertTrue(Schema::hasTable('pos_refunds'));
        $this->assertTrue(Schema::hasTable('pos_refund_lines'));

        $this->assertTrue(Schema::hasColumn('repair_requests', 'payment_policy_snapshot'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'payment_status_derived'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'total_paid_amount'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'total_refunded_amount'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'latest_pos_transaction_id'));
    }

    #[Test]
    public function repair_request_exposes_pos_relationships(): void
    {
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-REL-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000009',
            'shoe_type' => 'Sneakers',
            'description' => 'POS relation test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
        ]);

        $this->assertTrue(method_exists($repair, 'posTransactions'));
        $this->assertTrue(method_exists($repair, 'latestPosTransaction'));
    }

    #[Test]
    public function pos_payment_records_deposit_and_updates_repair_derived_status(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000001',
            'shoe_type' => 'Sneakers',
            'description' => 'POS payment test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-deposit-derived-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status_derived);
        $this->assertSame('500.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function manual_mark_paid_endpoint_is_blocked_and_returns_pos_instruction(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-002',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000002',
            'shoe_type' => 'Sneakers',
            'description' => 'Manual payment block test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1500,
            'final_total' => 1500,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
        ]);

        $response = $this->actingAs($actor, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-paid-in-shop");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'POS_REQUIRED');
    }

    #[Test]
    public function successful_checkout_creates_receipt_record_with_print_and_digital_payloads(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-003',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000011',
            'shoe_type' => 'Sneakers',
            'description' => 'Receipt generation test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-receipt-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseCount('pos_receipts', 1);
        $receipt = \App\Models\PosReceipt::query()->firstOrFail();
        $this->assertNotEmpty($receipt->receipt_no);
        $this->assertIsArray($receipt->print_payload);
        $this->assertIsArray($receipt->digital_payload);
    }

    #[Test]
    public function deposit_then_balance_transitions_to_paid(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-004',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000021',
            'shoe_type' => 'Sneakers',
            'description' => 'Deposit then balance lifecycle test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-deposit-balance-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ])->assertOk();

        $repair->update(['status' => 'ready_for_pickup']);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-deposit-balance-002',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status_derived);
        $this->assertSame('1000.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function full_upfront_transitions_to_paid_after_single_checkout(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-005',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000022',
            'shoe_type' => 'Sneakers',
            'description' => 'Full upfront lifecycle test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1200,
            'final_total' => 1200,
            'status' => 'pending',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-full-upfront-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1200],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status_derived);
        $this->assertSame('1200.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function non_cash_checkout_requires_provider_reference(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-006',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000023',
            'shoe_type' => 'Sneakers',
            'description' => 'Non-cash ref required test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-noncash-ref-required-001',
            'payment_lines' => [
                ['tender_type' => 'paymongo_wallet', 'amount' => 500, 'provider_reference' => null],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_lines.0.provider_reference']);
    }

    #[Test]
    public function non_cash_checkout_accepts_provider_reference_and_persists_it(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-007',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000024',
            'shoe_type' => 'Sneakers',
            'description' => 'Non-cash ref success test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-noncash-ref-success-001',
            'payment_lines' => [
                ['tender_type' => 'paymongo_card', 'amount' => 500, 'provider_reference' => 'AUTH-REF-12345'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('pos_payment_lines', [
            'tender_type' => 'paymongo_card',
            'provider_reference' => 'AUTH-REF-12345',
            'status' => 'paid',
        ]);
    }

    #[Test]
    public function full_upfront_policy_rejects_non_full_due_type(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-008',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000025',
            'shoe_type' => 'Sneakers',
            'description' => 'Policy/due type guard test (full_upfront)',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-policy-fullupfront-invalid-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1000],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_type']);
    }

    #[Test]
    public function deposit_policy_rejects_full_due_type(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-009',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000026',
            'shoe_type' => 'Sneakers',
            'description' => 'Policy/due type guard test (deposit_50)',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-policy-deposit-invalid-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_type']);
    }

    #[Test]
    public function deposit_checkout_updates_payment_status_to_paid(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-010',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000027',
            'shoe_type' => 'Sneakers',
            'description' => 'Payment status sync (deposit)',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-status-deposit-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status);
    }

    #[Test]
    public function full_checkout_updates_payment_status_to_paid_for_full_upfront_policy(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-011',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000028',
            'shoe_type' => 'Sneakers',
            'description' => 'Payment status sync (full upfront)',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-status-fullupfront-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1000],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status);
    }

    #[Test]
    public function shop_actor_can_approve_and_execute_manual_repair_refund(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $shopActor */
        $shopActor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $managerRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'user']);
        $shopActor->assignRole($managerRole);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-012',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000029',
            'shoe_type' => 'Sneakers',
            'description' => 'Refund lifecycle test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'picked_up',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
        ]);

        $checkout = $this->actingAs($shopActor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-refund-lifecycle-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1000],
            ],
        ])->assertOk();

        $transactionId = (int) $checkout->json('transaction_id');

        $refundRequest = $this->actingAs($customer, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => $transactionId,
            'request_type' => 'full',
            'requested_amount' => 1000,
            'reason_code' => 'customer_refund_request',
            'reason_notes' => 'Refund requested from test',
        ])->assertOk();

        $refundId = (int) $refundRequest->json('refund_id');

        $this->actingAs($shopActor, 'user')
            ->postJson("/api/repair-pos/refunds/{$refundId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.finance_status', 'approved_initial')
            ->assertJsonPath('data.shop_owner_status', 'pending');

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repair-refunds/{$refundId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.finance_status', 'approved')
            ->assertJsonPath('data.shop_owner_status', 'approved');

        $this->actingAs($shopActor, 'user')
            ->postJson("/api/repair-pos/refunds/{$refundId}/execute", ['execution_mode' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertDatabaseHas('pos_refunds', [
            'id' => $refundId,
            'status' => 'succeeded',
            'execution_mode' => 'manual',
        ]);

        $repair->refresh();
        $this->assertSame('1000.00', number_format((float) $repair->total_refunded_amount, 2, '.', ''));
        $this->assertSame('refunded', (string) $repair->payment_status_derived);
    }

    #[Test]
    public function customer_can_view_own_repair_refunds(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $otherCustomer */
        $otherCustomer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $shopActor */
        $shopActor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-013',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000030',
            'shoe_type' => 'Sneakers',
            'description' => 'Refund visibility test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'picked_up',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
        ]);

        $otherRepair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-014',
            'customer_name' => $otherCustomer->name,
            'email' => $otherCustomer->email,
            'phone' => '09170000031',
            'shoe_type' => 'Sneakers',
            'description' => 'Other refund visibility test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $otherCustomer->id,
            'intake_delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'picked_up',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
        ]);

        $tx1 = $this->actingAs($shopActor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-refund-visibility-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 1000]],
        ])->assertOk();

        $tx2 = $this->actingAs($shopActor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $otherRepair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $otherCustomer->id,
            'idempotency_key' => 'tdd-refund-visibility-002',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 1000]],
        ])->assertOk();

        $this->actingAs($customer, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => (int) $tx1->json('transaction_id'),
            'request_type' => 'full',
            'requested_amount' => 1000,
            'reason_code' => 'customer_refund_request',
        ])->assertOk();

        $this->actingAs($otherCustomer, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => (int) $tx2->json('transaction_id'),
            'request_type' => 'full',
            'requested_amount' => 1000,
            'reason_code' => 'customer_refund_request',
        ])->assertOk();

        $response = $this->actingAs($customer, 'user')->getJson('/api/repair-pos/refunds/mine');
        $response->assertOk()->assertJsonPath('success', true);

        $refunds = $response->json('data');
        $this->assertIsArray($refunds);
        $this->assertCount(1, $refunds);
        $this->assertSame((int) $repair->id, (int) ($refunds[0]['module_reference_id'] ?? 0));
    }
}
