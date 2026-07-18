<?php

namespace Tests\Feature\Manager;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
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
}
