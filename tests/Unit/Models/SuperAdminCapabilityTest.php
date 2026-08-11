<?php

namespace Tests\Unit\Models;

use App\Models\SuperAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SuperAdminCapabilityTest extends TestCase
{
    #[DataProvider('capabilityMatrix')]
    public function test_role_capability_matrix(string $role, string $capability, bool $expected): void
    {
        $admin = SuperAdmin::factory()->make(['role' => $role]);

        $this->assertSame($expected, $admin->hasCapability($capability));
    }

    public static function capabilityMatrix(): array
    {
        return [
            ['admin', SuperAdmin::CAP_REVIEW_REGISTRATIONS, true],
            ['admin', SuperAdmin::CAP_INTERVENE_ACCOUNTS, true],
            ['admin', SuperAdmin::CAP_MANAGE_ADMINISTRATORS, false],
            ['admin', SuperAdmin::CAP_RESOLVE_APPEALS, false],
            ['admin', SuperAdmin::CAP_MANAGE_PLANS, false],
            ['admin', SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS, false],
            ['super_admin', SuperAdmin::CAP_REVIEW_REGISTRATIONS, true],
            ['super_admin', SuperAdmin::CAP_INTERVENE_ACCOUNTS, true],
            ['super_admin', SuperAdmin::CAP_MANAGE_ADMINISTRATORS, true],
            ['super_admin', SuperAdmin::CAP_RESOLVE_APPEALS, true],
            ['super_admin', SuperAdmin::CAP_MANAGE_PLANS, true],
            ['super_admin', SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS, true],
        ];
    }
}
