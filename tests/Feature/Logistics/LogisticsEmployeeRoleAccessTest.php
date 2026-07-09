<?php

namespace Tests\Feature\Logistics;

use App\Models\ShopOwner;
use App\Services\BusinessAccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsEmployeeRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_shop_can_create_logistics_employee_roles(): void
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $roles = app(BusinessAccessControlService::class)->getAllowedRoles($shop);

        $this->assertContains('Logistics Dispatcher', $roles);
        $this->assertContains('Logistics Rider', $roles);
    }

    public function test_individual_shop_still_cannot_create_employee_roles(): void
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $roles = app(BusinessAccessControlService::class)->getAllowedRoles($shop);

        $this->assertSame([], $roles);
    }
}
