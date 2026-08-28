<?php

namespace Tests\Feature\Manager;

use App\Models\ShopOwner;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagerReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create();
        Role::findOrCreate('Manager', 'user');
        $this->manager = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Manager']);
        $this->manager->assignRole('Manager');
    }

    private function generateReport(array $overrides = [])
    {
        return $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', array_merge([
                'report_type' => 'sales',
                'date_range' => 'week',
                'notes' => 'Weekly manager review',
            ], $overrides));
    }

    public function test_non_manager_cannot_generate_reports(): void
    {
        $staff = User::factory()->for($this->shop)->create(['role' => 'Staff']);

        $this->actingAs($staff, 'user')
            ->postJson('/api/manager/reports/generate', [
                'report_type' => 'sales',
                'date_range' => 'week',
            ])
            ->assertForbidden();
    }

    public function test_legacy_staff_performance_endpoint_uses_canonical_assignment_identity(): void
    {
        $staffA = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        $staffB = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        Order::factory()->for($this->shop)->create([
            'order_number' => 'PERF-A-1-'.$staffA->id,
            'assigned_staff_id' => $staffA->id,
            'status' => 'processing',
        ]);
        Order::factory()->for($this->shop)->create([
            'order_number' => 'PERF-A-2-'.$staffA->id,
            'assigned_staff_id' => $staffA->id,
            'status' => 'processing',
        ]);
        Order::factory()->for($this->shop)->create([
            'order_number' => 'PERF-B-1-'.$staffB->id,
            'assigned_staff_id' => $staffB->id,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/staff-performance?per_page=10')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['data'],
                'period' => ['start', 'end'],
                'last_updated_at',
            ]);

        $rows = collect($response->json('data.data'))->keyBy('id');

        $this->assertSame(2, (int) $rows[$staffA->id]['active_orders']);
        $this->assertSame(1, (int) $rows[$staffB->id]['active_orders']);
        $this->assertNotSame(
            $rows[$staffA->id]['active_orders'],
            $rows[$staffB->id]['active_orders'],
        );
    }

    public function test_manager_can_generate_report(): void
    {
        $response = $this->generateReport()
            ->assertCreated()
            ->assertJsonStructure([
                'report' => ['id', 'report_type', 'report_title', 'date_range', 'status', 'generated_at'],
                'download_url',
            ]);

        $this->assertSame('sales', $response->json('report.report_type'));
        $this->assertSame('generated', $response->json('report.status'));
        $this->assertDatabaseHas('manager_reports', [
            'id' => $response->json('report.id'),
            'shop_owner_id' => $this->shop->id,
            'generated_by' => $this->manager->id,
        ]);
    }

    public function test_manager_can_generate_supported_report_and_range(): void
    {
        $this->generateReport([
            'report_type' => 'stock',
            'date_range' => 'month',
        ])->assertCreated()->assertJsonPath('report.report_type', 'stock')
            ->assertJsonPath('report.date_range', 'month');
    }

    public function test_report_generation_validates_type_and_range(): void
    {
        $this->generateReport(['report_type' => 'invalid'])
            ->assertUnprocessable();

        $this->generateReport(['date_range' => 'invalid'])
            ->assertUnprocessable();
    }

    public function test_manager_can_mark_report_as_reviewed(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/review", [
                'notes' => 'Reviewed for the weekly operations meeting.',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'reviewed');

        $this->assertDatabaseHas('manager_reports', [
            'id' => $reportId,
            'status' => 'reviewed',
            'reviewed_by' => $this->manager->id,
        ]);
    }

    public function test_report_generation_and_review_create_append_only_audit_events(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $generatedAudit = AuditLog::query()
            ->where('action', 'report_generated')
            ->where('target_type', 'manager_report')
            ->where('target_id', $reportId)
            ->firstOrFail();

        $this->assertSame([], $generatedAudit->metadata['previous_state']);
        $this->assertSame(['status' => 'generated'], $generatedAudit->metadata['new_state']);
        $this->assertSame('manager-report:' . $reportId, $generatedAudit->metadata['reference_id']);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/review", [
                'notes' => 'Report reviewed for operations.',
            ])
            ->assertOk();

        $reviewAudits = AuditLog::query()
            ->where('action', 'report_reviewed')
            ->where('target_type', 'manager_report')
            ->where('target_id', $reportId)
            ->get();

        $this->assertCount(1, $reviewAudits);
        $this->assertSame(['status' => 'generated'], $reviewAudits->first()->metadata['previous_state']);
        $this->assertSame(['status' => 'reviewed'], $reviewAudits->first()->metadata['new_state']);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/send", [
                'notes' => 'Repeated compatibility request.',
            ])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'report_reviewed')
            ->where('target_id', $reportId)
            ->count());
    }

    public function test_legacy_send_alias_uses_review_semantics(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/send", [
                'notes' => 'Reviewed through the compatibility endpoint.',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'reviewed');

        $this->assertDatabaseHas('manager_reports', [
            'id' => $reportId,
            'status' => 'reviewed',
            'reviewed_by' => $this->manager->id,
        ]);
    }

    public function test_review_requires_notes(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/review", [])
            ->assertUnprocessable();
    }

    public function test_repeated_review_is_idempotent_and_preserves_the_original_audit_fields(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $first = $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/review", [
                'notes' => 'First review decision.',
            ])
            ->assertOk();

        $reviewedAt = $first->json('report.reviewed_at');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/review", [
                'notes' => 'A repeated request with different notes.',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.reviewed_at', $reviewedAt);

        $this->assertDatabaseHas('manager_reports', [
            'id' => $reportId,
            'status' => 'reviewed',
            'notes' => 'First review decision.',
            'reviewed_by' => $this->manager->id,
        ]);
        $this->assertSame(1, \App\Models\ManagerReport::query()->count());
    }

    public function test_repeated_generation_with_the_same_idempotency_key_returns_the_original_report(): void
    {
        $first = $this->actingAs($this->manager, 'user')
            ->withHeader('Idempotency-Key', 'weekly-sales-2026-08-28')
            ->postJson('/api/manager/reports/generate', [
                'report_type' => 'sales',
                'date_range' => 'week',
                'notes' => 'Original report request.',
            ])
            ->assertCreated();

        $second = $this->actingAs($this->manager, 'user')
            ->withHeader('Idempotency-Key', 'weekly-sales-2026-08-28')
            ->postJson('/api/manager/reports/generate', [
                'report_type' => 'sales',
                'date_range' => 'week',
                'notes' => 'Repeated report request.',
            ])
            ->assertCreated();

        $this->assertSame($first->json('report.id'), $second->json('report.id'));
        $this->assertSame(1, \App\Models\ManagerReport::query()->count());
        $this->assertDatabaseHas('manager_reports', [
            'id' => $first->json('report.id'),
            'notes' => 'Original report request.',
            'generated_by' => $this->manager->id,
        ]);
    }

    public function test_sales_report_uses_canonical_order_and_order_item_fields(): void
    {
        $staff = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        $order = Order::factory()->for($this->shop)->create([
            'order_number' => 'CANONICAL-SALES-1',
            'customer_name' => 'Canonical Customer',
            'total_amount' => 1250.50,
            'assigned_staff_id' => $staff->id,
            'status' => 'delivered',
            'created_at' => now()->subDays(2),
        ]);
        $order->items()->create([
            'product_name' => 'Canonical Shoe',
            'price' => 1000,
            'quantity' => 1,
            'subtotal' => 1000,
        ]);
        $order->items()->create([
            'product_name' => 'Care Kit',
            'price' => 250.50,
            'quantity' => 1,
            'subtotal' => 250.50,
        ]);

        $reportId = $this->generateReport()->assertCreated()->json('report.id');
        $report = \App\Models\ManagerReport::query()->findOrFail($reportId);
        $row = collect($report->report_data['rows'])->firstWhere('order_id', $order->id);

        $this->assertNotNull($row);
        $this->assertSame('Canonical Customer', $row['customer_name']);
        $this->assertSame('1250.50', $row['total_amount']);
        $this->assertSame($staff->id, $row['assigned_staff_id']);
        $this->assertSame([
            ['product_name' => 'Canonical Shoe', 'quantity' => 1, 'subtotal' => '1000.00'],
            ['product_name' => 'Care Kit', 'quantity' => 1, 'subtotal' => '250.50'],
        ], $row['order_items']);
        $this->assertArrayNotHasKey('customer', $row);
        $this->assertArrayNotHasKey('product', $row);
        $this->assertArrayNotHasKey('total', $row);
        $this->assertSame('1250.50', $report->report_data['summary']['total_revenue']);
    }

    public function test_performance_report_attributes_orders_to_the_assigned_staff_and_selected_shop_period(): void
    {
        $staffA = User::factory()->for($this->shop)->create(['role' => 'STAFF', 'status' => 'active']);
        $staffB = User::factory()->for($this->shop)->create(['role' => 'STAFF', 'status' => 'active']);
        $otherShop = ShopOwner::factory()->create();
        $otherStaff = User::factory()->for($otherShop)->create(['role' => 'STAFF', 'status' => 'active']);

        Order::factory()->for($this->shop)->create([
            'assigned_staff_id' => $staffA->id,
            'total_amount' => 100,
            'status' => 'delivered',
            'created_at' => now()->subDays(2),
        ]);
        Order::factory()->for($this->shop)->create([
            'assigned_staff_id' => $staffA->id,
            'total_amount' => 200,
            'status' => 'processing',
            'created_at' => now()->subDays(1),
        ]);
        Order::factory()->for($this->shop)->create([
            'assigned_staff_id' => $staffB->id,
            'total_amount' => 50,
            'status' => 'delivered',
            'created_at' => now()->subDays(2),
        ]);
        Order::factory()->for($this->shop)->create([
            'assigned_staff_id' => $staffB->id,
            'total_amount' => 999,
            'status' => 'delivered',
            'created_at' => now()->subDays(30),
        ]);
        Order::factory()->for($otherShop)->create([
            'assigned_staff_id' => $otherStaff->id,
            'total_amount' => 5000,
            'status' => 'delivered',
            'created_at' => now()->subDays(2),
        ]);

        $reportId = $this->generateReport([
            'report_type' => 'performance',
            'date_range' => 'week',
        ])->assertCreated()->json('report.id');
        $rows = collect(\App\Models\ManagerReport::query()->findOrFail($reportId)->report_data['rows'])->keyBy('staff_id');

        $this->assertSame(2, $rows[$staffA->id]['order_count']);
        $this->assertSame('300.00', $rows[$staffA->id]['total_revenue']);
        $this->assertSame(1, $rows[$staffB->id]['order_count']);
        $this->assertSame('50.00', $rows[$staffB->id]['total_revenue']);
        $this->assertArrayNotHasKey($otherStaff->id, $rows->all());
    }

    public function test_manager_can_download_report_as_csv(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->get("/api/manager/reports/{$reportId}/download")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition');
    }

    public function test_manager_cannot_download_another_shops_report(): void
    {
        $otherShop = ShopOwner::factory()->create();
        $otherManager = User::factory()->for($otherShop)->create(['role' => 'Manager']);
        $otherManager->assignRole('Manager');

        $reportId = $this->actingAs($otherManager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'report_type' => 'sales',
                'date_range' => 'week',
            ])
            ->assertCreated()
            ->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}/download")
            ->assertNotFound();
    }

    public function test_manager_can_list_their_reports(): void
    {
        $this->generateReport(['report_type' => 'sales'])->assertCreated();
        $this->generateReport(['report_type' => 'stock'])->assertCreated();

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/reports')
            ->assertOk()
            ->assertJsonStructure([
                'metrics' => ['reports_generated', 'pending_issues', 'reports_reviewed', 'last_updated_at'],
                'report_types',
                'recent_reports' => [
                    '*' => ['id', 'report_type', 'status', 'generated_at'],
                ],
            ]);

        $this->assertCount(2, $response->json('recent_reports'));
    }

    public function test_nonexistent_report_download_returns_not_found(): void
    {
        $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/reports/99999/download')
            ->assertNotFound();
    }
}
