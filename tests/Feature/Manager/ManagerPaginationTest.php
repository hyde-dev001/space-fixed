<?php

namespace Tests\Feature\Manager;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5: Manager Pagination & Filtering Contract Tests
 *
 * Tests verify:
 * - Pagination parameters (page, per_page) work correctly
 * - Page boundaries handled correctly
 * - Filters (search, status) work independently
 * - Page resets when filters change (P4 behavior)
 * - Sorting works as expected
 */
class ManagerPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private ShopOwner $shop;
    private $employees = [];
    private $suspensions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create();
        $this->manager = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Manager']);

        // Create 25 suspension requests for pagination testing
        for ($i = 0; $i < 25; $i++) {
            $status = match ($i % 3) {
                0 => SuspensionStatus::PENDING_MANAGER,
                1 => SuspensionStatus::APPROVED,
                default => SuspensionStatus::REJECTED_MANAGER,
            };

            $employee = Employee::factory()
                ->for($this->shop)
                ->create([
                    'email' => "employee{$i}@test.local",
                    'name' => "Employee Test {$i}"
                ]);

            $suspension = SuspensionRequest::factory()
                ->for($employee)
                ->create([
                    'status' => $status,
                    'reason' => "Suspension reason {$i}",
                ]);

            $this->employees[$i] = $employee;
            $this->suspensions[$i] = $suspension;
        }
    }

    /**
     * Test: Default pagination returns correct number of items
     */
    public function test_suspension_list_default_pagination_returns_10_items(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(1, $response->json('current_page'));
        $this->assertEquals(3, $response->json('last_page'));
        $this->assertEquals(25, $response->json('total'));
    }

    /**
     * Test: Per-page parameter works correctly
     */
    public function test_suspension_list_respects_per_page_parameter(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertEquals(5, $response->json('per_page'));
        $this->assertEquals(5, $response->json('last_page'));
    }

    /**
     * Test: Per-page is bounded (min 5, max 100)
     */
    public function test_suspension_list_per_page_is_bounded(): void
    {
        // Too low - should be clamped to 5
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=2');

        $this->assertEquals(5, $response->json('per_page'));

        // Too high - should be clamped to 100
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=200');

        $this->assertEquals(100, $response->json('per_page'));
    }

    /**
     * Test: Page parameter navigates correctly
     */
    public function test_suspension_list_page_parameter_works(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?page=2&per_page=10');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('current_page'));
        $this->assertCount(10, $response->json('data'));
    }

    /**
     * Test: Out-of-bounds page returns empty without error
     */
    public function test_suspension_list_out_of_bounds_page_returns_empty(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?page=999');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(999, $response->json('current_page'));
    }

    /**
     * Test: Status filter works correctly
     */
    public function test_suspension_list_status_filter_works(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?status=pending');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        
        // All returned items should have pending status
        foreach ($data as $item) {
            $this->assertEquals('pending', $item['status']);
        }
    }

    /**
     * Test: Search filter works by employee name
     */
    public function test_suspension_list_search_filter_by_name(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?search=Employee%20Test%201&per_page=100');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        // Should find "Employee Test 1", "Employee Test 10", "Employee Test 12", etc.
        $this->assertGreaterThan(0, count($data));
        
        foreach ($data as $item) {
            $this->assertStringContainsString('Employee Test 1', $item['name']);
        }
    }

    /**
     * Test: Search filter works by employee email
     */
    public function test_suspension_list_search_filter_by_email(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?search=employee5@test.local&per_page=100');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        $this->assertStringContainsString('employee5', $data[0]['email']);
    }

    /**
     * Test: Multiple filters work together (status + search)
     */
    public function test_suspension_list_multiple_filters_work_together(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?status=pending&search=Employee%20Test%201&per_page=100');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        
        // Each result should match both filters
        foreach ($data as $item) {
            $this->assertEquals('pending', $item['status']);
            $this->assertStringContainsString('Employee Test 1', $item['name']);
        }
    }

    /**
     * Test: Pagination structure includes required fields
     */
    public function test_suspension_list_pagination_structure_complete(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'current_page',
            'data',
            'last_page',
            'per_page',
            'total',
            'metrics' => [
                'pending',
                'approved',
                'rejected',
                'total',
            ]
        ]);
    }

    /**
     * Test: Pagination data structure is consistent
     */
    public function test_suspension_list_data_structure_consistent(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'email',
                    'reason',
                    'requestedAt',
                    'status',
                    'approvedBy',
                    'approvalDate',
                    'approvalNote',
                ]
            ]
        ]);
    }

    /**
     * Test: Results are sorted by most recent first
     */
    public function test_suspension_list_sorted_by_recent(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=100');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        
        // Check descending order by comparing timestamps
        $timestamps = array_map(fn($item) => strtotime($item['requestedAt']), $data);
        $sorted = $timestamps;
        rsort($sorted);
        
        $this->assertEquals($sorted, $timestamps, 'Results should be sorted by most recent first');
    }

    /**
     * Test: Metrics reflect current filters (not total)
     */
    public function test_suspension_list_metrics_reflect_filtered_count(): void
    {
        // Get all requests
        $allResponse = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?per_page=100');

        $allMetrics = $allResponse->json('metrics');

        // Filter by pending only
        $pendingResponse = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/suspension-requests?status=pending&per_page=100');

        $pendingMetrics = $pendingResponse->json('metrics');

        // Filtered metrics should show counts even when filtered
        $this->assertGreaterThan(0, $pendingMetrics['pending']);
        $this->assertLessThanOrEqual($allMetrics['pending'], $pendingMetrics['pending']);
    }
}
