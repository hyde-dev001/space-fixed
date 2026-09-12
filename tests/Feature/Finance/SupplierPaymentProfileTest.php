<?php

namespace Tests\Feature\Finance;

use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", [
                'destination_type' => 'bank_account',
                'bank_name' => 'Test Bank',
                'bank_code' => 'TBK',
                'account_name' => 'Supplier Trading',
                'account_number' => '1234567890',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.masked_account_number', '******7890')
            ->assertJsonPath('data.status', 'unverified')
            ->assertJsonMissingPath('data.account_number');

        $profile = SupplierPaymentProfile::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('1234567890', $profile->account_number);
        $this->assertNotSame('1234567890', DB::table('supplier_payment_profiles')->whereKey($profile->id)->value('account_number'));
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
            ->putJson("/api/erp/procurement/suppliers/{$supplier->id}/payment-profile", [
                'destination_type' => 'bank_account',
                'bank_name' => 'Updated Bank',
                'bank_code' => 'UBK',
                'account_name' => 'Updated Supplier Trading',
                'account_number' => '0987654321',
            ])
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
    private function financeActor(?ShopOwner $shop = null): array
    {
        $shop ??= ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo(['access-finance-expenses', 'access-approval-workflow']);

        return [$shop, $user];
    }

    private function createProfile(ShopOwner $shop, Supplier $supplier): SupplierPaymentProfile
    {
        return SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'destination_type' => 'bank_account',
            'bank_name' => 'Test Bank',
            'bank_code' => 'TBK',
            'account_name' => 'Supplier Trading',
            'account_number' => '1234567890',
            'status' => SupplierPaymentProfile::STATUS_UNVERIFIED,
        ]);
    }
}
