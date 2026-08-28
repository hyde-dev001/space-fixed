<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Employee;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ManagerOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Staff', 'user');
        Permission::findOrCreate('access-staff-job-orders', 'user');
        Permission::findOrCreate('access-manager-job-orders', 'user');
        Permission::findOrCreate('reassign-manager-job-orders', 'user');

        $this->shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
    }

    public function test_pending_order_is_claimed_by_staff_when_processing_starts(): void
    {
        [$staff] = $this->staffWithEmployee();
        $order = Order::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'status' => 'pending',
        ]);

        $this->actingAs($staff, 'user')
            ->patchJson("/api/staff/orders/{$order->id}/status", [
                'status' => 'processing',
            ])
            ->assertOk();

        $claimed = $order->fresh();
        $this->assertSame($staff->id, (int) $claimed->assigned_staff_id);
        $this->assertNotNull($claimed->assigned_at);
        $this->assertSame('auto', $claimed->assignment_method);
        $this->assertSame($staff->id, (int) $claimed->assigned_by);

        $audit = AuditLog::query()
            ->where('action', 'order_claimed')
            ->where('target_id', $order->id)
            ->firstOrFail();

        $this->assertSame($staff->id, (int) $audit->actor_user_id);
        $this->assertSame(['assigned_staff_id' => null], $audit->metadata['previous_state']);
        $this->assertSame(['assigned_staff_id' => $staff->id], $audit->metadata['new_state']);
        $this->assertSame('order:' . $order->id, $audit->metadata['reference_id']);
    }

    public function test_second_staff_member_cannot_process_an_order_owned_by_staff_a(): void
    {
        [$staffA] = $this->staffWithEmployee();
        [$staffB] = $this->staffWithEmployee();
        $order = Order::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'status' => 'pending',
        ]);

        $this->actingAs($staffA, 'user')
            ->patchJson("/api/staff/orders/{$order->id}/status", [
                'status' => 'processing',
            ])
            ->assertOk();

        $this->actingAs($staffB, 'user')
            ->patchJson("/api/staff/orders/{$order->id}/status", [
                'status' => 'shipped',
            ])
            ->assertStatus(409);

        $this->assertSame('processing', $order->fresh()->status->value);
        $this->assertSame($staffA->id, (int) $order->fresh()->assigned_staff_id);
    }

    public function test_manager_order_list_is_shop_scoped_paginated_and_exposes_assignment_state(): void
    {
        $manager = $this->managerWithPermissions(['access-manager-job-orders']);
        [$staff] = $this->staffWithEmployee();
        $order = Order::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'status' => 'processing',
        ]);
        $order->forceFill([
            'assigned_staff_id' => $staff->id,
            'assigned_at' => now()->subHours(3),
            'assignment_method' => 'auto',
            'assigned_by' => $staff->id,
        ])->save();

        $response = $this->actingAs($manager, 'user')
            ->getJson('/api/manager/orders?per_page=10');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [[
                        'id',
                        'order_number',
                        'status',
                        'assigned_staff',
                        'age_minutes',
                        'overdue',
                        'lock_state',
                        'assignment_state',
                        'next_action',
                    ]],
                    'current_page',
                    'per_page',
                ],
            ]);
    }

    public function test_manager_cannot_reassign_an_order_while_current_handler_is_eligible(): void
    {
        $manager = $this->managerWithPermissions(['reassign-manager-job-orders']);
        [$staffA] = $this->staffWithEmployee();
        [$staffB] = $this->staffWithEmployee();
        $order = $this->assignedOrder($staffA);

        $this->actingAs($manager, 'user')
            ->postJson("/api/manager/orders/{$order->id}/reassign", [
                'replacement_staff_id' => $staffB->id,
                'reason' => 'Routine balancing is not allowed.',
            ])
            ->assertStatus(422);

        $this->assertSame($staffA->id, (int) $order->fresh()->assigned_staff_id);
    }

    public function test_manager_can_reassign_an_order_when_handler_is_inactive_and_preserve_handoff_metadata(): void
    {
        $manager = $this->managerWithPermissions(['reassign-manager-job-orders']);
        [$staffA, $employeeA] = $this->staffWithEmployee();
        [$staffB] = $this->staffWithEmployee();
        $order = $this->assignedOrder($staffA);
        $employeeA->update(['status' => 'inactive']);

        $this->actingAs($manager, 'user')
            ->postJson("/api/manager/orders/{$order->id}/reassign", [
                'replacement_staff_id' => $staffB->id,
                'reason' => 'Original handler is inactive and cannot continue this order.',
            ])
            ->assertOk();

        $reassigned = $order->fresh();
        $this->assertSame($staffB->id, (int) $reassigned->assigned_staff_id);
        $this->assertSame('manual', $reassigned->assignment_method);
        $this->assertSame($manager->id, (int) $reassigned->assigned_by);

        $audit = AuditLog::query()
            ->where('action', 'order_reassigned')
            ->where('target_id', $order->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($manager->id, (int) $audit->actor_user_id);
        $this->assertSame($staffA->id, (int) $audit->metadata['previous_handler_id']);
        $this->assertSame($staffB->id, (int) $audit->metadata['replacement_handler_id']);
        $this->assertSame(
            'Original handler is inactive and cannot continue this order.',
            $audit->metadata['reason'],
        );
    }

    public function test_manager_cannot_reassign_to_a_staff_member_from_another_shop(): void
    {
        $manager = $this->managerWithPermissions(['reassign-manager-job-orders']);
        [$staffA] = $this->staffWithEmployee();
        $order = $this->assignedOrder($staffA);
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        [$otherStaff] = $this->staffWithEmployee($otherShop);
        $staffA->employee()->update(['status' => 'inactive']);

        $this->actingAs($manager, 'user')
            ->postJson("/api/manager/orders/{$order->id}/reassign", [
                'replacement_staff_id' => $otherStaff->id,
                'reason' => 'Cross-shop replacement must not be accepted.',
            ])
            ->assertStatus(422);

        $this->assertSame($staffA->id, (int) $order->fresh()->assigned_staff_id);
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function staffWithEmployee(?ShopOwner $shop = null): array
    {
        $shop ??= $this->shop;
        $email = fake()->unique()->safeEmail();
        $employee = Employee::factory()->for($shop)->create([
            'email' => $email,
            'status' => 'active',
        ]);
        $staff = User::factory()->for($shop)->create([
            'email' => $email,
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        $staff->assignRole('Staff');
        $staff->givePermissionTo('access-staff-job-orders');

        return [$staff, $employee];
    }

    private function managerWithPermissions(array $permissions): User
    {
        $manager = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        foreach ($permissions as $permission) {
            $manager->givePermissionTo($permission);
        }

        return $manager;
    }

    private function assignedOrder(User $staff): Order
    {
        $order = Order::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'status' => 'processing',
        ]);
        $order->forceFill([
            'assigned_staff_id' => $staff->id,
            'assigned_at' => now()->subHours(2),
            'assignment_method' => 'auto',
            'assigned_by' => $staff->id,
        ])->save();

        return $order->fresh();
    }
}
