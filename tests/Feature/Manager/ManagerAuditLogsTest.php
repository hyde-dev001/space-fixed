<?php

namespace Tests\Feature\Manager;

use App\Models\ShopOwner;
use App\Models\User;
use App\Models\AuditLog as ConsolidatedAuditLog;
use App\Models\HR\AuditLog as HrAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagerAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create();
        $this->manager = User::factory()->for($this->shop)->create(['role' => 'Manager']);
        Role::findOrCreate('Manager', 'user');
        $this->manager->assignRole('Manager');
    }

    private function createActivity(array $attributes = []): Activity
    {
        return Activity::query()->create(array_merge([
            'log_name' => 'default',
            'description' => 'Record created',
            'event' => 'created',
            'subject_type' => ShopOwner::class,
            'subject_id' => $this->shop->id,
            'causer_type' => User::class,
            'causer_id' => $this->manager->id,
            'properties' => [],
        ], $attributes));
    }

    public function test_manager_can_access_audit_logs(): void
    {
        $response = $this->actingAs($this->manager, 'user')->getJson('/api/activity-logs');

        $response->assertOk()->assertJsonStructure([
            'logs' => [
                'data' => [
                    '*' => ['id', 'event', 'subject_type', 'subject_id', 'causer', 'created_at'],
                ],
                'per_page', 'current_page', 'total', 'last_page',
            ],
            'stats' => ['total_logs', 'logs_last_24h', 'event_counts', 'subject_type_counts'],
        ]);
    }

    public function test_non_manager_cannot_access_audit_logs(): void
    {
        $staff = User::factory()->for($this->shop)->create(['role' => 'Staff']);

        $this->actingAs($staff, 'user')
            ->getJson('/api/activity-logs')
            ->assertForbidden();
    }

    public function test_event_filter_is_applied(): void
    {
        $this->createActivity(['event' => 'created']);
        $this->createActivity(['event' => 'updated', 'description' => 'Record updated']);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?event=created')
            ->assertOk()
            ->json('logs.data');

        $this->assertNotEmpty($data);
        $this->assertSame(['created'], array_values(array_unique(array_column($data, 'event'))));
    }

    public function test_subject_type_filter_is_applied(): void
    {
        $this->createActivity(['subject_type' => ShopOwner::class]);
        $this->createActivity(['subject_type' => User::class, 'subject_id' => $this->manager->id]);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?subject_type=User')
            ->assertOk()
            ->json('logs.data');

        $this->assertNotEmpty($data);
        foreach ($data as $log) {
            $this->assertStringContainsString('User', $log['subject_type']);
        }
    }

    public function test_page_and_per_page_parameters_are_respected(): void
    {
        foreach (range(1, 25) as $index) {
            $this->createActivity(['description' => "Record {$index} created"]);
        }

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?page=2&per_page=10')
            ->assertOk();

        $this->assertSame(2, $response->json('logs.current_page'));
        $this->assertSame(10, $response->json('logs.per_page'));
        $this->assertCount(10, $response->json('logs.data'));
    }

    public function test_multiple_filters_compose(): void
    {
        $this->createActivity(['event' => 'updated', 'subject_type' => User::class, 'subject_id' => $this->manager->id]);
        $this->createActivity(['event' => 'created']);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?event=updated&subject_type=User')
            ->assertOk()
            ->json('logs.data');

        $this->assertCount(1, $data);
        $this->assertSame('updated', $data[0]['event']);
        $this->assertSame(User::class, $data[0]['subject_type']);
    }

    public function test_causer_filter_is_applied(): void
    {
        $otherManager = User::factory()->for($this->shop)->create(['role' => 'Manager']);
        $this->createActivity();
        $this->createActivity(['causer_id' => $otherManager->id]);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?causer_id=' . $this->manager->id)
            ->assertOk()
            ->json('logs.data');

        $this->assertCount(1, $data);
        $this->assertSame($this->manager->id, $data[0]['causer']['id']);
    }

    public function test_date_range_filters_are_applied(): void
    {
        $this->createActivity(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        $this->createActivity(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?date_from=' . now()->subDays(7)->toDateString() . '&date_to=' . now()->toDateString())
            ->assertOk()
            ->json('logs.data');

        $this->assertCount(1, $data);
    }

    public function test_default_sorting_is_recent_first(): void
    {
        $older = $this->createActivity(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
        $newer = $this->createActivity(['created_at' => now(), 'updated_at' => now()]);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs')
            ->assertOk()
            ->json('logs.data');

        $this->assertSame([$newer->id, $older->id], array_column($data, 'id'));
    }

    public function test_invalid_filter_parameter_is_ignored(): void
    {
        $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?invalid_param=value')
            ->assertOk();
    }

    public function test_empty_filter_result_returns_empty_data(): void
    {
        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs?event=nonexistent_event')
            ->assertOk()
            ->json('logs.data');

        $this->assertSame([], $data);
    }

    public function test_manager_sees_only_their_shops_audit_logs(): void
    {
        $otherShop = ShopOwner::factory()->create();
        $otherManager = User::factory()->for($otherShop)->create(['role' => 'Manager']);

        $this->createActivity();
        $this->createActivity([
            'subject_id' => $otherShop->id,
            'causer_id' => $otherManager->id,
        ]);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/activity-logs')
            ->assertOk()
            ->json('logs.data');

        $this->assertCount(1, $data);
        $this->assertSame($this->shop->id, $data[0]['subject_id']);
    }

    public function test_explicit_audit_read_capability_can_access_the_canonical_manager_endpoint(): void
    {
        $auditReader = User::factory()->for($this->shop)->create(['role' => 'Staff']);
        Permission::findOrCreate('access-audit-logs', 'user');
        $auditReader->givePermissionTo('access-audit-logs');

        $this->actingAs($auditReader, 'user')
            ->getJson('/api/activity-logs')
            ->assertOk();

        $this->actingAs($auditReader, 'user')
            ->getJson('/api/manager/audit-logs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'action',
                        'actor',
                        'target',
                        'created_at',
                        'previous_state',
                        'new_state',
                        'reason',
                        'reference_id',
                        'correlation_id',
                    ],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                'stats' => ['total_logs', 'logs_last_24h', 'action_counts'],
                'last_updated_at',
            ]);
    }

    public function test_canonical_manager_audit_logs_merge_operational_and_hr_events_with_readable_context(): void
    {
        $orderAudit = ConsolidatedAuditLog::query()->create([
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->manager->id,
            'actor_user_id' => $this->manager->id,
            'action' => 'order_reassigned',
            'object_type' => 'order',
            'object_id' => 101,
            'target_type' => 'order',
            'target_id' => 101,
            'metadata' => [
                'previous_state' => ['assigned_staff_id' => 4],
                'new_state' => ['assigned_staff_id' => 8],
                'reason' => 'Handler is no longer available.',
                'reference_id' => 'ORD-101',
                'correlation_id' => 'corr-order-101',
            ],
        ]);

        $leaveAudit = HrAuditLog::query()->create([
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->manager->id,
            'module' => 'leave',
            'action' => 'approved',
            'entity_type' => 'App\\Models\\HR\\LeaveRequest',
            'entity_id' => 202,
            'description' => 'Leave request approved by Manager',
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'approved', 'reason' => 'Coverage confirmed.'],
            'severity' => HrAuditLog::SEVERITY_WARNING,
            'tags' => ['leave', 'approval'],
        ]);

        $data = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/audit-logs')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $orderRow = collect($data)->firstWhere('id', 'central:' . $orderAudit->id);
        $leaveRow = collect($data)->firstWhere('id', 'hr:' . $leaveAudit->id);

        $this->assertNotNull($orderRow);
        $this->assertSame('order_reassigned', $orderRow['action']);
        $this->assertSame($this->manager->id, $orderRow['actor']['id']);
        $this->assertSame('order', $orderRow['target']['type']);
        $this->assertSame(101, $orderRow['target']['id']);
        $this->assertSame(['assigned_staff_id' => 4], $orderRow['previous_state']);
        $this->assertSame(['assigned_staff_id' => 8], $orderRow['new_state']);
        $this->assertSame('Handler is no longer available.', $orderRow['reason']);
        $this->assertSame('ORD-101', $orderRow['reference_id']);
        $this->assertSame('corr-order-101', $orderRow['correlation_id']);

        $this->assertNotNull($leaveRow);
        $this->assertSame('approved', $leaveRow['action']);
        $this->assertSame(['status' => 'pending'], $leaveRow['previous_state']);
        $this->assertSame('Coverage confirmed.', $leaveRow['reason']);
    }

    public function test_canonical_manager_audit_logs_normalize_legacy_activity_state_changes(): void
    {
        $activity = $this->createActivity([
            'event' => 'updated',
            'description' => 'Order status updated',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => 303,
            'properties' => [
                'old_status' => 'pending',
                'new_status' => 'processing',
                'reference_id' => 'ORD-303',
            ],
        ]);

        $row = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/audit-logs')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('activity:' . $activity->id, $row['id']);
        $this->assertSame('updated', $row['action']);
        $this->assertSame(['status' => 'pending'], $row['previous_state']);
        $this->assertSame(['status' => 'processing'], $row['new_state']);
        $this->assertSame('ORD-303', $row['reference_id']);
    }

    public function test_canonical_manager_audit_logs_include_business_friendly_presentation_fields(): void
    {
        $activity = $this->createActivity([
            'event' => 'subscription_cancelled',
            'description' => 'subscription_cancelled',
            'subject_type' => 'App\\Models\\ShopOwnerSubscription',
            'subject_id' => 2,
        ]);

        $row = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/audit-logs')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('activity:' . $activity->id, $row['id']);
        $this->assertSame('Subscription Cancelled', $row['action_label']);
        $this->assertSame('Subscription', $row['target']['type_label']);
        $this->assertSame('Subscription', $row['target']['label']);
        $this->assertSame('Subscription Cancelled', $row['display_description']);
        $this->assertStringNotContainsString('App\\Models\\', $row['display_description']);
        $this->assertStringNotContainsString('#2', $row['target']['label']);
    }

    public function test_canonical_manager_audit_target_filter_accepts_business_reference(): void
    {
        $activity = $this->createActivity([
            'event' => 'updated',
            'description' => 'Order status updated',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => 303,
            'properties' => [
                'reference_id' => 'ORD-303',
            ],
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/audit-logs?target=ORD-303')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('activity:' . $activity->id, $response->json('data.0.id'));
    }

    public function test_canonical_manager_audit_logs_are_tenant_scoped_and_support_filters_and_pagination(): void
    {
        $otherShop = ShopOwner::factory()->create();
        $otherManager = User::factory()->for($otherShop)->create(['role' => 'Manager']);

        foreach (range(1, 12) as $index) {
            ConsolidatedAuditLog::query()->create([
                'shop_owner_id' => $this->shop->id,
                'user_id' => $this->manager->id,
                'actor_user_id' => $this->manager->id,
                'action' => 'repair_reassigned',
                'object_type' => 'repair_request',
                'object_id' => $index,
                'target_type' => 'repair_request',
                'target_id' => $index,
                'metadata' => ['reason' => "Repair handoff {$index}"],
            ]);
        }

        ConsolidatedAuditLog::query()->create([
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->manager->id,
            'actor_user_id' => $this->manager->id,
            'action' => 'order_reassigned',
            'object_type' => 'order',
            'object_id' => 900,
            'target_type' => 'order',
            'target_id' => 900,
            'metadata' => ['reason' => 'Different event'],
        ]);

        ConsolidatedAuditLog::query()->create([
            'shop_owner_id' => $otherShop->id,
            'user_id' => $otherManager->id,
            'actor_user_id' => $otherManager->id,
            'action' => 'repair_reassigned',
            'object_type' => 'repair_request',
            'object_id' => 999,
            'target_type' => 'repair_request',
            'target_id' => 999,
            'metadata' => ['reason' => 'Other tenant'],
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/audit-logs?action=repair_reassigned&target_id=6&page=1&per_page=5')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.last_page'));
        $this->assertSame(6, $response->json('data.0.target.id'));
        $this->assertSame(1, $response->json('stats.total_logs'));
        $this->assertFalse(collect($response->json('data'))->contains(fn (array $row): bool => $row['target']['id'] === 999));
    }
}
