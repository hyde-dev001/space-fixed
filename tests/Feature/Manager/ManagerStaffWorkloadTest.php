<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ManagerStaffWorkloadTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Manager', 'user');
        $this->shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);
        $this->manager = User::factory()->for($this->shop)->create([
            'role' => 'MANAGER',
            'status' => 'active',
        ]);
        $this->manager->assignRole('Manager');
    }

    public function test_manager_gets_distinct_shop_scoped_staff_workloads(): void
    {
        config([
            'manager.order_sla_minutes' => 60,
            'manager.repair_sla_minutes' => 60,
        ]);

        $staffA = $this->staff(['name' => 'Inactive Staff', 'status' => 'inactive']);
        $staffB = $this->staff(['name' => 'Active Staff']);
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $otherStaff = User::factory()->for($otherShop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        $this->createOrders($staffA, 2, now()->subHours(3));
        $this->createOrders($staffB, 1, now()->subMinutes(10));
        $this->createOrders($otherStaff, 4, now()->subHours(3));
        Order::factory()->for($this->shop)->create([
            'order_number' => 'WORKLOAD-OLD-'.$staffA->id.'-'.uniqid(),
            'assigned_staff_id' => $staffA->id,
            'assigned_at' => now()->subDays(10),
            'status' => 'delivered',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->createRepairs($staffA, 1, now()->subHours(3));
        $this->createRepairs($staffB, 2, now()->subMinutes(10));

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/staff-workload?per_page=10&date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [[
                        'id',
                        'name',
                        'role',
                        'status',
                        'availability_state',
                        'active_orders',
                        'active_repairs',
                        'overdue_work',
                        'requires_order_reassignment',
                        'next_action',
                        'links' => ['orders', 'repairs'],
                    ]],
                    'current_page',
                    'per_page',
                ],
                'period' => ['start', 'end'],
                'last_updated_at',
            ]);

        $rows = collect($response->json('data.data'))->keyBy('id');

        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[$staffA->id]['active_orders']);
        $this->assertSame(1, (int) $rows[$staffA->id]['active_repairs']);
        $this->assertSame(3, (int) $rows[$staffA->id]['overdue_work']);
        $this->assertSame(2, (int) $rows[$staffA->id]['period_orders']);
        $this->assertSame(0, (int) $rows[$staffA->id]['period_completed_orders']);
        $this->assertTrue($rows[$staffA->id]['requires_order_reassignment']);
        $this->assertSame('inactive', $rows[$staffA->id]['availability_state']);

        $this->assertSame(1, (int) $rows[$staffB->id]['active_orders']);
        $this->assertSame(2, (int) $rows[$staffB->id]['active_repairs']);
        $this->assertSame(0, (int) $rows[$staffB->id]['overdue_work']);
        $this->assertSame(1, (int) $rows[$staffB->id]['period_orders']);
        $this->assertFalse($rows[$staffB->id]['requires_order_reassignment']);
        $this->assertSame('active', $rows[$staffB->id]['availability_state']);
    }

    public function test_off_shift_is_not_inferred_as_an_automatic_reassignment_trigger(): void
    {
        $staff = $this->staff(['name' => 'Active After Shift', 'status' => 'active']);
        $this->createOrders($staff, 1, now()->subHours(3));

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/staff-workload?per_page=10')
            ->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $staff->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['requires_order_reassignment']);
        $this->assertSame('active', $row['availability_state']);
    }

    public function test_retail_only_staff_workload_exposes_orders_without_repair_workload(): void
    {
        $this->shop->update(['business_type' => 'retail']);

        $staff = $this->staff(['name' => 'Retail Staff']);
        $repairer = User::factory()->for($this->shop)->create([
            'name' => 'Repairer Not Applicable',
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);

        $this->createOrders($staff, 1, now()->subMinutes(10));
        $this->createRepairs($repairer, 2, now()->subMinutes(10));

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/staff-workload?per_page=10')
            ->assertOk()
            ->assertJsonPath('business_capabilities.business_type', 'retail')
            ->assertJsonPath('business_capabilities.can_retail', true)
            ->assertJsonPath('business_capabilities.can_repair', false);

        $rows = collect($response->json('data.data'))->keyBy('id');

        $this->assertSame(1, (int) $rows[$staff->id]['active_orders']);
        $this->assertSame(0, (int) $rows[$staff->id]['active_repairs']);
        $this->assertSame(1, (int) $rows[$staff->id]['total_active_work']);
        $this->assertSame(0, (int) $rows[$staff->id]['period_repairs']);
        $this->assertFalse($rows[$staff->id]['requires_repair_reassignment']);

        $this->assertSame(0, (int) $rows[$repairer->id]['active_repairs']);
        $this->assertSame(0, (int) $rows[$repairer->id]['total_active_work']);
    }

    public function test_staff_workload_filters_by_search_role_status_and_paginates(): void
    {
        $staffA = $this->staff(['name' => 'Alpha Staff']);
        $staffB = $this->staff(['name' => 'Beta Staff', 'status' => 'inactive']);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/staff-workload?search=Alpha&role=staff&status=active&per_page=5')
            ->assertOk();

        $this->assertSame(1, (int) $response->json('data.total'));
        $this->assertSame($staffA->id, (int) $response->json('data.data.0.id'));
        $this->assertNotSame($staffB->id, (int) $response->json('data.data.0.id'));
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->for($this->shop)->create(array_merge([
            'role' => 'STAFF',
            'status' => 'active',
        ], $attributes));
    }

    private function createOrders(User $staff, int $count, $createdAt): void
    {
        foreach (range(1, $count) as $index) {
            Order::factory()->for($this->shop)->create([
                'order_number' => 'WORKLOAD-'.$staff->id.'-'.$index.'-'.uniqid(),
                'assigned_staff_id' => $staff->id,
                'assigned_at' => $createdAt,
                'status' => 'processing',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    private function createRepairs(User $repairer, int $count, $createdAt): void
    {
        foreach (range(1, $count) as $index) {
            RepairRequest::factory()->for($this->shop)->create([
                'request_id' => 'WORKLOAD-REP-'.$repairer->id.'-'.$index.'-'.uniqid(),
                'assigned_repairer_id' => $repairer->id,
                'assigned_at' => $createdAt,
                'status' => 'in_progress',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
