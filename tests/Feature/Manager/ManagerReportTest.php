<?php

namespace Tests\Feature\Manager;

use App\Models\ShopOwner;
use App\Models\User;
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

    public function test_manager_can_mark_report_as_sent(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/send", [
                'notes' => 'Reviewed and sent to the shop owner.',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'sent');

        $this->assertDatabaseHas('manager_reports', [
            'id' => $reportId,
            'status' => 'sent',
            'sent_by' => $this->manager->id,
        ]);
    }

    public function test_sending_report_requires_notes(): void
    {
        $reportId = $this->generateReport()->assertCreated()->json('report.id');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/send", [])
            ->assertUnprocessable();
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
                'metrics' => ['reports_generated', 'pending_issues', 'reports_sent'],
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
