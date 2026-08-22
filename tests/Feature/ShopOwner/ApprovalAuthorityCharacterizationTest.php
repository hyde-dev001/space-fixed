<?php

namespace Tests\Feature\ShopOwner;

use App\Models\Employee;
use App\Models\HR\SalaryChange;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApprovalAuthorityCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('approve-salary-change', 'user');
    }

    public function test_existing_non_proposer_salary_reviewer_approves_without_applying_salary(): void
    {
        [$change, $proposer, $reviewer, $employee] = $this->salaryChangeContext();

        $response = $this->actingAs($reviewer, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/approve", [
                'notes' => 'Existing authorized reviewer decision',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SalaryChange::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by', $reviewer->id);

        $this->assertDatabaseHas('salary_changes', [
            'id' => $change->id,
            'status' => SalaryChange::STATUS_APPROVED,
            'approved_by' => $reviewer->id,
        ]);
        $this->assertSame((string) $employee->salary, (string) $employee->fresh()->salary);
        $this->assertNotSame($proposer->id, $reviewer->id);
    }

    public function test_salary_change_proposer_cannot_self_approve(): void
    {
        [$change, $proposer] = $this->salaryChangeContext();
        $proposer->givePermissionTo('approve-salary-change');

        $response = $this->actingAs($proposer, 'user')
            ->postJson("/api/hr/salary-changes/{$change->id}/approve");

        $response->assertForbidden();
        $this->assertDatabaseHas('salary_changes', [
            'id' => $change->id,
            'status' => SalaryChange::STATUS_PENDING,
            'approved_by' => null,
        ]);
    }

    public function test_existing_manager_final_review_owns_repair_rejection_decision(): void
    {
        $shopOwner = ShopOwner::factory()->create(['business_type' => 'repair']);
        Role::findOrCreate('Manager', 'user');
        $manager = User::factory()->for($shopOwner)->create(['role' => 'Manager']);
        $manager->assignRole('Manager');
        $repair = RepairRequest::factory()->for($shopOwner)->create([
            'status' => 'manager_reviewing',
            'repairer_rejected_at' => now()->subHour(),
            'manager_decision' => 'approve_rejection',
            'manager_review_notes' => 'Existing manager review',
        ]);

        $response = $this->actingAs($manager, 'user')
            ->postJson("/api/manager/repairs/{$repair->id}/finalize-rejection", [
                'notes' => 'Existing manager final decision',
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('repair_requests', [
            'id' => $repair->id,
            'status' => 'rejected',
            'manager_reviewed_by' => $manager->id,
            'manager_decision' => 'approve_rejection',
        ]);
    }

    /** @return array{SalaryChange, User, User, Employee} */
    private function salaryChangeContext(): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $proposer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $reviewer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $reviewer->givePermissionTo('approve-salary-change');

        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shopOwner->id,
            'salary' => 1000,
        ]);

        $change = SalaryChange::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shopOwner->id,
            'proposed_by' => $proposer->id,
            'previous_salary' => 1000,
            'new_salary' => 1100,
            'change_percent' => 10,
            'change_type' => SalaryChange::TYPE_MAJOR,
            'effective_date' => now()->toDateString(),
            'reason' => 'Characterize the existing approval authority',
            'status' => SalaryChange::STATUS_PENDING,
        ]);

        return [$change, $proposer, $reviewer, $employee];
    }
}
