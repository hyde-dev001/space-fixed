<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\AuditLog;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Manager\ManagerRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ManagerRepairWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Repairer', 'user');
        Permission::findOrCreate('access-manager-repair-jobs', 'user');
        Permission::findOrCreate('review-manager-repair-jobs', 'user');

        $this->shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'repair_workload_limit' => 10,
        ]);

        $this->manager = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        $this->manager->givePermissionTo([
            'access-manager-repair-jobs',
            'review-manager-repair-jobs',
        ]);
    }

    public function test_manager_repair_list_is_shop_scoped_paginated_and_exposes_workload_review_state(): void
    {
        $repairerA = $this->repairer();
        $repairerB = $this->repairer();

        $this->activeRepair($repairerA, 'in_progress');
        $listed = $this->activeRepair($repairerA, 'assigned_to_repairer');
        $this->activeRepair($repairerB, 'repairer_accepted');
        $this->activeRepair($repairerA, 'completed');

        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        RepairRequest::factory()->for($otherShop)->create([
            'status' => 'repairer_rejected',
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/repairs?per_page=2&repairer_id='.$repairerA->id.'&status=assigned_to_repairer');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [[
                        'id',
                        'request_id',
                        'status',
                        'assigned_repairer',
                        'repairer_workload',
                        'age_minutes',
                        'overdue',
                        'review_state',
                        'next_action',
                    ]],
                    'current_page',
                    'per_page',
                ],
            ]);

        $this->assertSame($listed->id, (int) $response->json('data.data.0.id'));
        $this->assertSame(2, (int) $response->json('data.data.0.repairer_workload'));
        $this->assertSame(1, (int) $response->json('data.total'));
    }

    public function test_new_repair_is_autoassigned_to_the_lowest_active_workload(): void
    {
        $repairerA = $this->repairer();
        $repairerB = $this->repairer();
        $this->activeRepair($repairerA, 'in_progress');

        $request = RepairRequest::factory()->for($this->shop)->create([
            'status' => 'pending',
            'assigned_repairer_id' => null,
        ]);

        $assigned = app(ManagerRepairService::class)->autoAssign($request->fresh());

        $this->assertSame($repairerB->id, (int) $assigned->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $assigned->status);
        $this->assertSame('auto', $assigned->assignment_method);
    }

    public function test_no_eligible_repairer_keeps_request_visible_as_awaiting_assignment(): void
    {
        $request = RepairRequest::factory()->for($this->shop)->create([
            'status' => 'pending',
            'assigned_repairer_id' => null,
        ]);

        $assigned = app(ManagerRepairService::class)->autoAssign($request->fresh());

        $this->assertSame('awaiting_assignment', (string) $assigned->status);
        $this->assertNull($assigned->assigned_repairer_id);
    }

    public function test_repeating_autoassignment_does_not_send_a_duplicate_repairer_notification(): void
    {
        $repairer = $this->repairer();
        $request = $this->activeRepair($repairer, 'assigned_to_repairer');

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->never())->method('notifyRepairerAssignment');
        $this->app->instance(NotificationService::class, $notifications);

        app(ManagerRepairService::class)->autoAssign($request->fresh());

        $this->assertSame($repairer->id, (int) $request->fresh()->assigned_repairer_id);
    }

    public function test_repairer_rejection_enters_manager_review_and_preserves_rejection_history(): void
    {
        $repairer = $this->repairer();
        $request = $this->activeRepair($repairer, 'assigned_to_repairer');

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$request->id}/reject", [
                'reason_text' => 'The repair cannot proceed safely with the available materials.',
                'reason_category' => 'parts_unavailable',
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $rejected = $request->fresh();
        $this->assertSame('repairer_rejected', (string) $rejected->status);
        $this->assertSame($repairer->id, (int) $rejected->repairer_rejected_by);
        $this->assertSame($repairer->id, (int) $rejected->assigned_repairer_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'repairer_rejected',
            'target_id' => $request->id,
        ]);
    }

    public function test_manager_can_reassign_after_repairer_rejection_without_takeover(): void
    {
        $repairerA = $this->repairer();
        $repairerB = $this->repairer();
        $request = $this->activeRepair($repairerA, 'repairer_rejected', [
            'repairer_rejected_by' => $repairerA->id,
            'repairer_rejection_reason' => 'This repair requires a different capability.',
            'repairer_rejected_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$request->id}/reassign", [
                'replacement_repairer_id' => $repairerB->id,
                'reason' => 'Assign to another eligible repairer after the rejection.',
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $reassigned = $request->fresh();
        $this->assertSame($repairerB->id, (int) $reassigned->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $reassigned->status);
        $this->assertSame('manual', $reassigned->assignment_method);
        $this->assertSame($this->manager->id, (int) $reassigned->assigned_by);
        $this->assertSame(1, (int) $reassigned->reassignment_count);

        $audit = AuditLog::query()
            ->where('action', 'repair_reassigned')
            ->where('target_id', $request->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($repairerA->id, (int) $audit->metadata['previous_repairer_id']);
        $this->assertSame($repairerB->id, (int) $audit->metadata['replacement_repairer_id']);
        $this->assertSame($this->manager->id, (int) $audit->actor_user_id);
    }

    public function test_manager_can_reassign_when_assigned_repairer_becomes_unavailable(): void
    {
        $repairerA = $this->repairer();
        $repairerB = $this->repairer();
        $request = $this->activeRepair($repairerA, 'in_progress');
        $repairerA->update(['status' => 'inactive']);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$request->id}/reassign", [
                'replacement_repairer_id' => $repairerB->id,
                'reason' => 'Assigned repairer became inactive while work was active.',
            ])
            ->assertOk();

        $this->assertSame($repairerB->id, (int) $request->fresh()->assigned_repairer_id);
    }

    public function test_manager_cannot_rebalance_a_healthy_active_repair(): void
    {
        $repairerA = $this->repairer();
        $repairerB = $this->repairer();
        $request = $this->activeRepair($repairerA, 'in_progress');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$request->id}/reassign", [
                'replacement_repairer_id' => $repairerB->id,
                'reason' => 'Routine workload balancing is not allowed.',
            ])
            ->assertStatus(422);

        $this->assertSame($repairerA->id, (int) $request->fresh()->assigned_repairer_id);
    }

    public function test_manager_final_rejection_is_terminal_and_does_not_forward_to_owner_by_default(): void
    {
        $repairer = $this->repairer();
        $request = $this->activeRepair($repairer, 'repairer_rejected', [
            'requires_owner_approval' => false,
            'repairer_rejected_by' => $repairer->id,
            'repairer_rejection_reason' => 'No safe repair path is available.',
            'repairer_rejected_at' => now()->subHour(),
        ]);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$request->id}/final-reject", [
                'reason' => 'Final Manager review confirms that the repair cannot be accepted.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $final = $request->fresh();
        $this->assertSame('rejected', (string) $final->status);
        $this->assertSame($repairer->id, (int) $final->assigned_repairer_id);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $this->shop->id,
            'type' => 'repair_rejection_review',
            'requires_action' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'repair_manager_final_rejected',
            'target_id' => $request->id,
        ]);
    }

    public function test_manager_reassignment_requires_reason_and_same_shop_eligible_repairer(): void
    {
        $repairerA = $this->repairer();
        $request = $this->activeRepair($repairerA, 'repairer_rejected', [
            'repairer_rejected_by' => $repairerA->id,
        ]);
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $otherRepairer = $this->repairer($otherShop);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$request->id}/reassign", [
                'replacement_repairer_id' => $otherRepairer->id,
            ])
            ->assertStatus(422);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/repairs/{$request->id}/reassign", [
                'replacement_repairer_id' => $repairerA->id,
                'reason' => 'Cannot reassign to the repairer who rejected the request.',
            ])
            ->assertStatus(422);
    }

    /**
     * @return User
     */
    private function repairer(?ShopOwner $shop = null): User
    {
        $shop ??= $this->shop;
        $repairer = User::factory()->for($shop)->create([
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        $repairer->assignRole('Repairer');

        return $repairer;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function activeRepair(User $repairer, string $status, array $attributes = []): RepairRequest
    {
        return RepairRequest::factory()->for($this->shop)->create(array_merge([
            'status' => $status,
            'assigned_repairer_id' => $repairer->id,
            'assigned_at' => now()->subHours(2),
        ], $attributes));
    }
}
