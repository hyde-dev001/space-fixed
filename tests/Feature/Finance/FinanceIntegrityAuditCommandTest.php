<?php

namespace Tests\Feature\Finance;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceIntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-payslip-approval', 'user');
        Permission::findOrCreate('access-approval-workflow', 'user');
        Permission::findOrCreate('disburse-payroll', 'user');
    }

    public function test_legacy_disburser_audit_is_read_only_and_stable(): void
    {
        $shop = ShopOwner::factory()->create();
        $legacy = User::factory()->create(['shop_owner_id' => $shop->id]);
        $legacy->givePermissionTo('access-payslip-approval');
        $explicit = User::factory()->create(['shop_owner_id' => $shop->id]);
        $explicit->givePermissionTo('disburse-payroll');

        $before = User::query()->count();
        $this->artisan('finance:audit-integrity', ['--section' => 'legacy-disbursers'])
            ->expectsOutput('section=legacy-disbursers count=1')
            ->expectsOutput("user_id={$legacy->id} shop_owner_id={$shop->id}")
            ->assertExitCode(0);

        $this->assertSame($before, User::query()->count());
    }

    public function test_job_invoice_audit_reports_a_clean_unique_set(): void
    {
        $this->artisan('finance:audit-integrity', ['--section' => 'job-invoices'])
            ->expectsOutput('section=job-invoices groups=0')
            ->assertExitCode(0);
    }
}
