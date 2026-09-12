<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\BusinessAccessControlService;
use App\Services\ShopModuleAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ShopModuleAccessServiceTest extends TestCase
{
    private ShopModuleAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ShopModuleAccessService(new BusinessAccessControlService);
    }

    /**
     * @dataProvider eligibleModuleProvider
     */
    public function test_eligible_module_combinations_are_allowed(
        string $registrationType,
        string $businessType,
        string $moduleKey,
    ): void {
        $owner = $this->owner($registrationType, $businessType, [[$moduleKey, true]]);

        $decision = $this->service->decide($owner, $moduleKey);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->code);
        $this->assertTrue($this->service->canAccess($owner, $moduleKey));
        $this->assertTrue($this->service->isEligible($owner, $moduleKey));
    }

    public static function eligibleModuleProvider(): array
    {
        return [
            'individual retail operations' => ['individual', 'retail', 'retail_operations'],
            'individual repair operations' => ['individual', 'repair', 'repair_operations'],
            'company retail inventory' => ['company', 'retail', 'inventory'],
            'company repair finance' => ['company', 'repair', 'finance'],
            'company both hr' => ['company', 'both', 'hr_employees'],
        ];
    }

    public function test_ineligible_and_invalid_legacy_states_fail_closed(): void
    {
        $individual = $this->owner('individual', 'retail', [['finance', true]]);
        $individualLogistics = $this->owner('individual', 'retail', [['logistics', true]]);
        $repairOnly = $this->owner('company', 'repair', [['retail_operations', true]]);
        $invalid = $this->owner('legacy', 'retail', [['retail_operations', true]]);

        $this->assertSame('MODULE_INELIGIBLE', $this->service->decide($individual, 'finance')->code);
        $this->assertSame('MODULE_INELIGIBLE', $this->service->decide($individualLogistics, 'logistics')->code);
        $this->assertFalse($this->service->isEligible($individualLogistics, 'logistics'));
        $this->assertSame('MODULE_INELIGIBLE', $this->service->decide($repairOnly, 'retail_operations')->code);
        $this->assertSame('MODULE_INELIGIBLE', $this->service->decide($invalid, 'retail_operations')->code);
        $this->assertFalse($this->service->isEligible($invalid, 'retail_operations'));
    }

    public function test_missing_disabled_and_unknown_modules_have_stable_denials(): void
    {
        $owner = $this->owner('company', 'both', [['inventory', false]]);

        $this->assertSame('MODULE_STATE_MISSING', $this->service->decide($owner, 'finance')->code);
        $this->assertSame('MODULE_DISABLED', $this->service->decide($owner, 'inventory')->code);
        $this->assertSame('UNKNOWN_MODULE', $this->service->decide($owner, 'unknown')->code);
        $this->assertFalse($this->service->canAccess($owner, 'unknown'));
    }

    public function test_gate_modes_are_explicit_and_unknown_keys_fail_closed(): void
    {
        $owner = $this->owner('company', 'both', [
            ['inventory', true],
            ['finance', false],
        ]);

        $this->assertTrue($this->service->decideGate($owner, 'single', ['inventory'])->allowed);
        $this->assertFalse($this->service->canAccessAll($owner, ['inventory', 'finance']));
        $this->assertTrue($this->service->canAccessAny($owner, ['finance', 'inventory']));
        $this->assertSame('UNKNOWN_MODULE', $this->service->decideGate($owner, 'any_of', ['unknown', 'inventory'])->code);
    }

    public function test_states_for_returns_every_catalog_key_and_safe_reasons(): void
    {
        $owner = $this->owner('company', 'retail', [
            ['inventory', true],
            ['finance', false],
        ]);

        $states = $this->service->statesFor($owner);

        $this->assertSame(array_keys(config('shop_modules.modules')), array_keys($states));
        $this->assertTrue($states['inventory']['accessible']);
        $this->assertSame('MODULE_DISABLED', $states['finance']['code']);
        $this->assertFalse($states['finance']['accessible']);
        $this->assertSame('MODULE_STATE_MISSING', $states['hr_employees']['code']);
        $this->assertArrayHasKey('reason', $states['inventory']);
    }

    public function test_enforcement_flag_does_not_make_service_treat_missing_state_as_enabled(): void
    {
        Config::set('shop_modules.enforcement_enabled', false);
        $owner = $this->owner('company', 'both');

        $decision = $this->service->decide($owner, 'inventory');

        $this->assertFalse($decision->allowed);
        $this->assertSame('MODULE_STATE_MISSING', $decision->code);
    }

    public function test_actor_resolution_distinguishes_owner_employee_and_customer(): void
    {
        $owner = $this->owner('company', 'both');
        $employee = new User(['shop_owner_id' => 42]);
        $employee->setRelation('shopOwner', $owner);
        $customer = new User(['shop_owner_id' => null]);

        $this->assertSame($owner, $this->service->resolveShopOwnerForActor($owner));
        $this->assertSame($owner, $this->service->resolveShopOwnerForActor($employee));
        $this->assertNull($this->service->resolveShopOwnerForActor($customer));
    }

    /**
     * @param  array<int, array{0: string, 1: bool}>  $modules
     */
    private function owner(string $registrationType, string $businessType, array $modules = []): ShopOwner
    {
        $owner = new ShopOwner([
            'id' => 42,
            'registration_type' => $registrationType,
            'business_type' => $businessType,
            'status' => 'approved',
        ]);

        $owner->setRelation('modules', new Collection(array_map(
            fn (array $module): ShopOwnerModule => new ShopOwnerModule([
                'shop_owner_id' => 42,
                'module_key' => $module[0],
                'enabled' => $module[1],
            ]),
            $modules,
        )));

        return $owner;
    }
}
