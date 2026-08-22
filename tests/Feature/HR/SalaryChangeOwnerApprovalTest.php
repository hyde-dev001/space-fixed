<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\HR\Payroll;
use App\Models\HR\SalaryChange;
use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalaryChangeOwnerApprovalTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwner;
    private User $ownerUser;
    private User $proposer;
    private User $reviewer;
    private User $hrManager;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        Permission::findOrCreate('manage-salary-changes', 'user');
        Permission::findOrCreate('approve-salary-change', 'user');
        Permission::findOrCreate('override-salary-retroactive', 'user');

        $this->shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);

        $this->ownerUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'email' => $this->shopOwner->email,
            'role' => 'Shop Owner',
        ]);

        $this->proposer = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
        $this->proposer->givePermissionTo([
            'manage-salary-changes',
            'approve-salary-change',
        ]);

        $this->reviewer = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
        $this->reviewer->givePermissionTo('approve-salary-change');

        $this->hrManager = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
        $this->hrManager->givePermissionTo('manage-salary-changes');

        $this->employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'salary' => 1000,
        ]);
    }

    public function test_owner_enabled_change_requires_exact_owner_and_records_audit_without_mutating_salary(): void
    {
        $this->setSalaryApprovalPolicy(true);
        $change = $this->submitChange();
        $this->assertTrue((bool) $change->requires_owner_approval);

        // The policy changed after submission; the stored ON workflow still
        // reserves its existing owner decision for the exact owner identity.
        $this->setSalaryApprovalPolicy(false);

        $this->actingAs($this->reviewer, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/approve", [
                'notes' => 'Generic reviewer cannot take the owner stage',
            ])
            ->assertForbidden();

        $response = $this->actingAsOwner()
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/approve", [
                'notes' => 'Owner approved the salary change',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SalaryChange::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by', $this->ownerUser->id);

        $change->refresh();
        $this->assertSame(SalaryChange::STATUS_APPROVED, $change->status);
        $this->assertSame($this->ownerUser->id, $change->approved_by);
        $this->assertSame('1000.00', (string) $this->employee->fresh()->salary);
        $this->assertSame(now()->addDays(3)->toDateString(), $change->effective_date->toDateString());

        $audit = AuditLog::query()
            ->where('action', 'salary_change_approved')
            ->where('entity_id', $change->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($this->ownerUser->id, (int) $audit->user_id);
    }

    public function test_owner_disabled_change_uses_the_existing_non_proposer_salary_reviewer(): void
    {
        $this->setSalaryApprovalPolicy(false);
        $change = $this->submitChange();
        $this->assertFalse((bool) $change->requires_owner_approval);

        // The policy changed after submission; OFF still uses the proven
        // SalaryChangeController::approve ->
        // SalaryChangeApprovalService::approveSalaryChange path.
        $this->setSalaryApprovalPolicy(true);

        $this->actingAsOwner()
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/approve", [
                'notes' => 'Owner stage is omitted when disabled',
            ])
            ->assertForbidden();

        $this->actingAs($this->proposer, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/approve")
            ->assertForbidden();

        $this->actingAs($this->hrManager, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/approve")
            ->assertForbidden();

        $response = $this->actingAs($this->reviewer, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/approve", [
                'notes' => 'Authorized non-proposer reviewer decision',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SalaryChange::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by', $this->reviewer->id);

        $change->refresh();
        $this->assertSame($this->reviewer->id, $change->approved_by);
        $this->assertSame('1000.00', (string) $this->employee->fresh()->salary);
    }

    public function test_cross_shop_owner_cannot_approve_salary_change(): void
    {
        $this->setSalaryApprovalPolicy(true);
        $change = $this->submitChange();
        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);

        Auth::guard('user')->logout();
        $this->actingAs($otherShopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/approve")
            ->assertNotFound();

        $this->assertSame(SalaryChange::STATUS_PENDING, $change->fresh()->status);
    }

    public function test_rejection_requires_a_reason_and_replay_is_stale(): void
    {
        $this->setSalaryApprovalPolicy(true);
        $change = $this->submitChange();

        $this->actingAsOwner()
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/reject")
            ->assertStatus(422);

        $this->actingAsOwner()
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/reject", [
                'notes' => 'Budget was not approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SalaryChange::STATUS_REJECTED)
            ->assertJsonPath('data.rejected_by', $this->ownerUser->id);

        $this->actingAsOwner()
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/approve")
            ->assertNotFound();
    }

    public function test_effective_date_keeps_application_separate_from_approval(): void
    {
        $this->setSalaryApprovalPolicy(true);
        $change = $this->submitChange(now()->addDays(7)->toDateString());

        $this->actingAsOwner()
            ->postJson("/api/shop-owner/salary-changes/{$change->id}/approve")
            ->assertOk();

        $this->assertSame('1000.00', (string) $this->employee->fresh()->salary);
        $this->assertFalse(SalaryChange::readyToApply()->whereKey($change->id)->exists());

        $this->actingAsOwner()
            ->postJson("/api/hr/salary-changes/{$change->id}/apply")
            ->assertForbidden();

        $change->update(['effective_date' => now()->subDay()->toDateString()]);
        $this->assertTrue(SalaryChange::readyToApply()->whereKey($change->id)->exists());

        $this->actingAs($this->proposer, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/apply")
            ->assertOk();

        $this->assertSame('1100.00', (string) $this->employee->fresh()->salary);
        $this->assertSame(SalaryChange::STATUS_APPLIED, $change->fresh()->status);
    }

    public function test_retroactive_submission_requires_override_reason(): void
    {
        $retroactiveDate = now()->subDays(5)->startOfDay();
        Payroll::create([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shopOwner->id,
            'payroll_period' => 'retro-' . now()->format('YmdHis') . random_int(100, 999),
            'pay_period_start' => $retroactiveDate->copy()->subDays(5)->toDateString(),
            'pay_period_end' => $retroactiveDate->copy()->addDays(2)->toDateString(),
            'base_salary' => 1000,
            'gross_salary' => 1000,
            'net_salary' => 1000,
            'status' => 'processed',
            'approval_status' => 'approved',
            'payment_method' => 'bank_transfer',
        ]);

        $this->actingAs($this->proposer, 'user')
            ->postJson('/api/hr/salary-changes', [
                'employee_id' => $this->employee->id,
                'new_salary' => 1100,
                'effective_date' => $retroactiveDate->toDateString(),
                'reason' => 'Retroactive market correction',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'RETROACTIVE_LOCKED');

        $this->proposer->givePermissionTo('override-salary-retroactive');

        $this->actingAs($this->proposer, 'user')
            ->postJson('/api/hr/salary-changes', [
                'employee_id' => $this->employee->id,
                'new_salary' => 1100,
                'effective_date' => $retroactiveDate->toDateString(),
                'reason' => 'Retroactive market correction',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('retroactive_override_reason');

        $response = $this->actingAs($this->proposer, 'user')
            ->postJson('/api/hr/salary-changes', [
                'employee_id' => $this->employee->id,
                'new_salary' => 1100,
                'effective_date' => $retroactiveDate->toDateString(),
                'reason' => 'Retroactive market correction',
                'retroactive_override_reason' => 'Payroll correction approved by HR governance',
            ]);

        $response->assertCreated();
        $change = SalaryChange::findOrFail($response->json('data.id'));
        $this->assertTrue((bool) $change->retroactive);
        $this->assertSame($this->proposer->id, $change->retroactive_override_by);
        $this->assertTrue((bool) $change->requires_owner_approval);
    }

    private function submitChange(?string $effectiveDate = null): SalaryChange
    {
        $response = $this->actingAs($this->proposer, 'user')
            ->postJson('/api/hr/salary-changes', [
                'employee_id' => $this->employee->id,
                'new_salary' => 1100,
                'effective_date' => $effectiveDate ?? now()->addDays(3)->toDateString(),
                'reason' => 'Approved salary governance test',
                'change_type' => SalaryChange::TYPE_MAJOR,
            ]);

        $response->assertCreated();

        return SalaryChange::findOrFail($response->json('data.id'));
    }

    private function setSalaryApprovalPolicy(bool $enabled): void
    {
        $settings = ProcurementSettings::getForShopOwner($this->shopOwner->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['salary_adjustment_approval']['enabled'] = $enabled;
        $settings->update(['settings_json' => $settingsJson]);
    }

    private function actingAsOwner()
    {
        Auth::guard('user')->logout();

        return $this->actingAs($this->shopOwner, 'shop_owner');
    }
}
