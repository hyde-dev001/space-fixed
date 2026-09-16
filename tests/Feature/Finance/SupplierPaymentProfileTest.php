<?php

namespace Tests\Feature\Finance;

use App\Models\ShopOwner;
use App\Models\Notification;
use App\Models\Supplier;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierPaymentProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([
            'access-suppliers-management',
            'procurement.manage_suppliers',
            'access-finance-expenses',
            'access-approval-workflow',
            'approve-expenses',
        ] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
    }

    public function test_procurement_can_create_a_masked_encrypted_profile(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

        $response = $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge($this->validBusinessRecipient(), [
                'destination_type' => 'bank_account',
                'bank_name' => 'Test Bank',
                'bank_code' => 'TBK',
                'account_name' => 'Supplier Trading',
                'account_number' => '1234567890',
            ]));

        $response->assertOk()
            ->assertJsonPath('data.masked_account_number', '******7890')
            ->assertJsonPath('data.status', 'unverified')
            ->assertJsonMissingPath('data.account_number');

        $profile = SupplierPaymentProfile::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('1234567890', $profile->account_number);
        $this->assertNotSame('1234567890', DB::table('supplier_payment_profiles')->whereKey($profile->id)->value('account_number'));
    }

    public function test_recipient_profiles_expose_identity_and_address_without_account_details(): void
    {
        [$shop] = $this->procurementActor();
        $businessSupplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $individualSupplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

        $business = SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $businessSupplier->id,
            'recipient_type' => 'business',
            'business_name' => 'Test Supplier PH',
            'recipient_country' => 'PH',
            'recipient_province_state' => 'Cavite',
            'recipient_city' => 'General Mariano Alvarez',
            'recipient_street_line_1' => '123 Test Street',
            'recipient_postal_code' => '4117',
            'destination_type' => 'bank_account',
            'bank_name' => 'BDO Unibank',
            'bank_code' => 'BNORPHMM',
            'account_name' => 'Test Supplier PH',
            'account_number' => '12345678',
            'status' => SupplierPaymentProfile::STATUS_UNVERIFIED,
        ]);
        $individual = SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $individualSupplier->id,
            'recipient_type' => 'individual',
            'given_name' => 'Juanito',
            'surname' => 'Dimaguiba',
            'recipient_country' => 'PH',
            'recipient_province_state' => 'Cavite',
            'recipient_city' => 'General Mariano Alvarez',
            'recipient_street_line_1' => '456 Sample Road',
            'recipient_street_line_2' => 'Barangay Sample',
            'recipient_postal_code' => '4117',
            'destination_type' => 'bank_account',
            'bank_name' => 'BDO Unibank',
            'bank_code' => 'BNORPHMM',
            'account_name' => 'Juanito Dimaguiba',
            'account_number' => '87654321',
            'status' => SupplierPaymentProfile::STATUS_UNVERIFIED,
        ]);

        $this->assertSame('business', $business->toMaskedArray()['recipient_type']);
        $this->assertSame('Test Supplier PH', $business->toMaskedArray()['business_name']);
        $this->assertNull($business->toMaskedArray()['given_name']);
        $this->assertSame('PH', $business->toMaskedArray()['recipient_country']);
        $this->assertArrayNotHasKey('account_number', $business->toMaskedArray());

        $this->assertSame('individual', $individual->toMaskedArray()['recipient_type']);
        $this->assertSame('Juanito', $individual->toMaskedArray()['given_name']);
        $this->assertSame('Dimaguiba', $individual->toMaskedArray()['surname']);
        $this->assertSame('Barangay Sample', $individual->toMaskedArray()['recipient_street_line_2']);
        $this->assertArrayNotHasKey('account_number', $individual->toMaskedArray());
    }

    public function test_business_and_individual_recipient_fields_are_conditionally_required(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $base = $this->validBusinessProfile();

        $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge($base, [
                'business_name' => null,
                'given_name' => 'Juanito',
            ]))
            ->assertJsonValidationErrors(['business_name', 'given_name']);

        $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge($base, [
                'recipient_type' => 'individual',
                'business_name' => 'Not Allowed',
                'given_name' => 'Juanito',
                'surname' => null,
            ]))
            ->assertJsonValidationErrors(['business_name', 'surname']);
    }

    public function test_recipient_changes_reset_verification_and_blank_account_preserves_encrypted_value(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        [, $finance] = $this->financeActor($shop);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = SupplierPaymentProfile::create(array_merge($this->validBusinessProfile(), [
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => SupplierPaymentProfile::STATUS_UNVERIFIED,
        ]));

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/suppliers/{$supplier->id}/payment-profile/verify")
            ->assertOk();

        $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge(
                $this->validBusinessProfile(),
                ['recipient_city' => 'Dasmarinas', 'account_number' => ''],
            ))
            ->assertOk()
            ->assertJsonPath('data.status', SupplierPaymentProfile::STATUS_UNVERIFIED);

        $profile->refresh();
        $this->assertSame('1234567890', $profile->account_number);
        $this->assertSame('Dasmarinas', $profile->recipient_city);
    }

    public function test_finance_cannot_verify_an_incomplete_legacy_recipient_profile(): void
    {
        [$shop, $finance] = $this->financeActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = $this->createProfile($shop, $supplier);
        $profile->forceFill(['recipient_postal_code' => null])->save();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/suppliers/{$supplier->id}/payment-profile/verify")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_reusable_e_wallet_profiles_use_explicit_wallet_fields(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

        $response = $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge($this->validBusinessRecipient(), [
                'destination_type' => 'e_wallet',
                'wallet_provider' => 'GCash',
                'account_name' => 'Supplier Trading',
                'account_identifier' => '09171234567',
            ]));

        $response->assertOk()
            ->assertJsonPath('data.destination_type', 'e_wallet')
            ->assertJsonPath('data.wallet_provider', 'GCash')
            ->assertJsonPath('data.masked_account_identifier', '*******4567')
            ->assertJsonMissingPath('data.account_identifier')
            ->assertJsonMissingPath('data.account_number');

        $profile = SupplierPaymentProfile::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('09171234567', $profile->account_identifier);
        $this->assertNull($profile->bank_name);
        $this->assertNull($profile->bank_code);
        $this->assertNotSame('09171234567', DB::table('supplier_payment_profiles')->whereKey($profile->id)->value('account_identifier'));
    }

    public function test_supplier_creation_can_atomically_include_an_e_wallet_profile(): void
    {
        [$shop, $procurement] = $this->procurementActor();

        $response = $this->actingAs($procurement, 'user')
            ->postJson('/api/erp/procurement/suppliers', [
                'name' => 'Wallet Supplier',
                'payment_profile' => array_merge($this->validBusinessRecipient(), [
                    'destination_type' => 'e_wallet',
                    'wallet_provider' => 'Maya',
                    'account_name' => 'Wallet Supplier Trading',
                    'account_identifier' => '09179876543',
                ]),
            ]);

        $response->assertCreated();
        $supplier = Supplier::query()->where('name', 'Wallet Supplier')->firstOrFail();
        $this->assertDatabaseHas('supplier_payment_profiles', [
            'supplier_id' => $supplier->id,
            'shop_owner_id' => $shop->id,
            'destination_type' => 'e_wallet',
            'wallet_provider' => 'Maya',
            'bank_name' => null,
            'bank_code' => null,
        ]);
        $this->assertSame('09179876543', $supplier->paymentProfile->account_identifier);
    }

    public function test_supplier_creation_rolls_back_when_the_nested_profile_is_invalid(): void
    {
        [$shop, $procurement] = $this->procurementActor();

        $this->actingAs($procurement, 'user')
            ->postJson('/api/erp/procurement/suppliers', [
                'name' => 'Invalid Wallet Supplier',
                'payment_profile' => [
                    'destination_type' => 'e_wallet',
                    'wallet_provider' => 'Maya',
                    'account_name' => 'Wallet Supplier Trading',
                ],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('suppliers', ['name' => 'Invalid Wallet Supplier']);
        $this->assertFalse(Schema::hasTable('supplier_payment_profiles') && DB::table('supplier_payment_profiles')->where('wallet_provider', 'Maya')->exists());
    }

    public function test_profile_changes_reset_finance_verification(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        [, $finance] = $this->financeActor($shop);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = $this->createProfile($shop, $supplier);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/suppliers/{$supplier->id}/payment-profile/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge($this->validBusinessRecipient(), [
                'destination_type' => 'bank_account',
                'bank_name' => 'Updated Bank',
                'bank_code' => 'UBK',
                'account_name' => 'Updated Supplier Trading',
                'account_number' => '0987654321',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'unverified');

        $this->assertDatabaseHas('supplier_payment_profiles', [
            'id' => $profile->id,
            'status' => 'unverified',
            'verified_by' => null,
            'verified_at' => null,
        ]);
    }

    public function test_finance_can_view_verify_and_disable_only_masked_profile_data(): void
    {
        [$shop, $finance] = $this->financeActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $this->createProfile($shop, $supplier);

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/suppliers/{$supplier->id}/payment-profile")
            ->assertOk()
            ->assertJsonPath('data.masked_account_number', '******7890')
            ->assertJsonMissingPath('data.account_number');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/suppliers/{$supplier->id}/payment-profile/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.verified_by', $finance->id);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/suppliers/{$supplier->id}/payment-profile/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertSame('1234567890', SupplierPaymentProfile::query()->firstOrFail()->account_number);
    }

    public function test_supplier_listing_exposes_payment_profile_status_without_sensitive_details(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        $verifiedSupplier = Supplier::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Verified Supplier',
        ]);
        $unverifiedSupplier = Supplier::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Unverified Supplier',
        ]);
        $noProfileSupplier = Supplier::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'No Profile Supplier',
        ]);

        $verifiedProfile = $this->createProfile($shop, $verifiedSupplier);
        $this->createProfile($shop, $unverifiedSupplier);
        $verifiedProfile->forceFill(['status' => SupplierPaymentProfile::STATUS_VERIFIED])->save();

        $response = $this->actingAs($procurement, 'user')
            ->getJson('/api/erp/procurement/suppliers');

        $response->assertOk();
        $suppliers = collect($response->json('data'))->keyBy('id');

        self::assertSame(SupplierPaymentProfile::STATUS_VERIFIED, $suppliers[$verifiedSupplier->id]['payment_profile_status']);
        self::assertSame(SupplierPaymentProfile::STATUS_UNVERIFIED, $suppliers[$unverifiedSupplier->id]['payment_profile_status']);
        self::assertNull($suppliers[$noProfileSupplier->id]['payment_profile_status']);
        self::assertArrayNotHasKey('account_number', $suppliers[$verifiedSupplier->id]);
        self::assertArrayNotHasKey('account_identifier', $suppliers[$verifiedSupplier->id]);
    }

    public function test_finance_can_explicitly_reveal_a_bank_account_for_a_payment(): void
    {
        [$shop, $finance] = $this->financeActor();
        [, $procurement] = $this->procurementActorFor($shop);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $this->createProfile($shop, $supplier);

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/suppliers/{$supplier->id}/payment-profile")
            ->assertOk()
            ->assertJsonMissingPath('data.account_number');

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/suppliers/{$supplier->id}/payment-profile/reveal")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.account_number', '1234567890')
            ->assertJsonPath('data.masked_account_number', '******7890')
            ->assertJsonMissingPath('data.account_identifier');

        $this->actingAs($procurement, 'user')
            ->getJson("/api/finance/suppliers/{$supplier->id}/payment-profile/reveal")
            ->assertForbidden();
    }

    public function test_finance_can_explicitly_reveal_an_e_wallet_identifier_for_a_payment(): void
    {
        [$shop, $finance] = $this->financeActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'destination_type' => SupplierPaymentProfile::DESTINATION_E_WALLET,
            'wallet_provider' => 'GCash',
            'account_name' => 'Supplier Trading',
            'account_identifier' => '09171234567',
            'status' => SupplierPaymentProfile::STATUS_VERIFIED,
        ]);

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/suppliers/{$supplier->id}/payment-profile/reveal")
            ->assertOk()
            ->assertJsonPath('data.account_identifier', '09171234567')
            ->assertJsonPath('data.masked_account_identifier', '*******4567')
            ->assertJsonMissingPath('data.account_number');
    }

    public function test_disabling_a_profile_notifies_procurement_and_allows_a_new_destination(): void
    {
        [$shop, $procurement] = $this->procurementActor();
        [, $finance] = $this->financeActor($shop);
        $otherShop = ShopOwner::factory()->create();
        [, $otherShopProcurement] = $this->procurementActorFor($otherShop);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id, 'name' => 'Northstar Trading']);
        $profile = $this->createProfile($shop, $supplier);

        $profile->forceFill([
            'status' => SupplierPaymentProfile::STATUS_VERIFIED,
            'verified_by' => $finance->id,
            'verified_at' => now(),
        ])->save();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/suppliers/{$supplier->id}/payment-profile/disable")
            ->assertOk()
            ->assertJsonPath('data.status', SupplierPaymentProfile::STATUS_DISABLED);

        $notification = Notification::query()
            ->where('user_id', $procurement->id)
            ->where('type', 'supplier_payment_profile_disabled')
            ->sole();

        self::assertSame('Northstar Trading Payment Profile Disabled', $notification->title);
        self::assertSame('/erp/procurement/suppliers-management?supplier=' . $supplier->id, $notification->action_url);
        self::assertSame($supplier->id, $notification->data['supplier_id']);
        self::assertSame(SupplierPaymentProfile::STATUS_DISABLED, $notification->data['status']);
        self::assertArrayNotHasKey('account_number', $notification->data);
        self::assertArrayNotHasKey('account_identifier', $notification->data);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherShopProcurement->id,
            'type' => 'supplier_payment_profile_disabled',
        ]);

        $this->actingAs($procurement, 'user')
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", array_merge($this->validBusinessRecipient(), [
                'destination_type' => 'e_wallet',
                'wallet_provider' => 'Maya',
                'account_name' => 'Northstar Trading',
                'account_identifier' => '09171234567',
            ]))
            ->assertOk()
            ->assertJsonPath('data.destination_type', SupplierPaymentProfile::DESTINATION_E_WALLET)
            ->assertJsonPath('data.status', SupplierPaymentProfile::STATUS_UNVERIFIED)
            ->assertJsonMissingPath('data.account_identifier');

        $profile->refresh();
        self::assertSame(SupplierPaymentProfile::STATUS_UNVERIFIED, $profile->status);
        self::assertNull($profile->verified_by);
        self::assertNull($profile->verified_at);
        self::assertSame('09171234567', $profile->account_identifier);
        self::assertSame('Maya', $profile->wallet_provider);
        self::assertNull($profile->bank_name);
        self::assertNull($profile->bank_code);
    }

    public function test_profile_routes_enforce_same_shop_and_role_boundaries(): void
    {
        [$shopA, $procurementA] = $this->procurementActor();
        [$shopB] = $this->financeActor();
        [, $financeA] = $this->financeActor($shopA);
        $supplierB = Supplier::factory()->create(['shop_owner_id' => $shopB->id]);
        $this->createProfile($shopB, $supplierB);

        $this->actingAs($procurementA, 'user')
            ->getJson("/api/erp/procurement/suppliers/{$supplierB->id}/payment-profile")
            ->assertNotFound();

        $this->actingAs($financeA, 'user')
            ->postJson("/api/finance/suppliers/{$supplierB->id}/payment-profile/verify")
            ->assertNotFound();

        $viewer = User::factory()->create(['shop_owner_id' => $shopA->id]);
        $viewer->givePermissionTo('access-finance-expenses');
        $supplierA = Supplier::factory()->create(['shop_owner_id' => $shopA->id]);
        $this->createProfile($shopA, $supplierA);

        $this->actingAs($viewer, 'user')
            ->getJson("/api/finance/suppliers/{$supplierA->id}/payment-profile")
            ->assertOk();

        $this->actingAs($viewer, 'user')
            ->postJson("/api/finance/suppliers/{$supplierA->id}/payment-profile/verify")
            ->assertForbidden();
    }

    /** @return array{0: ShopOwner, 1: User} */
    private function procurementActor(): array
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo(['access-suppliers-management', 'procurement.manage_suppliers']);

        return [$shop, $user];
    }

    /** @return array{0: ShopOwner, 1: User} */
    private function procurementActorFor(ShopOwner $shop): array
    {
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo(['access-suppliers-management', 'procurement.manage_suppliers']);

        return [$shop, $user];
    }

    /** @return array{0: ShopOwner, 1: User} */
    private function financeActor(?ShopOwner $shop = null): array
    {
        $shop ??= ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo(['access-finance-expenses', 'access-approval-workflow']);

        return [$shop, $user];
    }

    private function createProfile(ShopOwner $shop, Supplier $supplier): SupplierPaymentProfile
    {
        return SupplierPaymentProfile::create(array_merge($this->validBusinessProfile(), [
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => SupplierPaymentProfile::STATUS_UNVERIFIED,
        ]));
    }

    /** @return array<string, mixed> */
    private function validBusinessProfile(): array
    {
        return array_merge($this->validBusinessRecipient(), [
            'destination_type' => SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT,
            'bank_name' => 'Test Bank',
            'bank_code' => 'TBK',
            'account_name' => 'Supplier Trading',
            'account_number' => '1234567890',
        ]);
    }

    /** @return array<string, mixed> */
    private function validBusinessRecipient(): array
    {
        return [
            'recipient_type' => SupplierPaymentProfile::RECIPIENT_BUSINESS,
            'business_name' => 'Supplier Trading',
            'recipient_country' => 'PH',
            'recipient_province_state' => 'Cavite',
            'recipient_city' => 'General Mariano Alvarez',
            'recipient_street_line_1' => '123 Test Street',
            'recipient_street_line_2' => null,
            'recipient_postal_code' => '4117',
        ];
    }
}
