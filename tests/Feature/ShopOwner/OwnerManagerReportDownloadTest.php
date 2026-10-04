<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ManagerReport;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OwnerManagerReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): ShopOwner
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        foreach (['retail_operations', 'repair_operations'] as $module) {
            ShopOwnerModule::factory()->create(['shop_owner_id' => $owner->id, 'module_key' => $module, 'enabled' => true]);
        }
        return $owner;
    }

    private function report(ShopOwner $owner, array $overrides = []): ManagerReport
    {
        return ManagerReport::create(array_merge([
            'shop_owner_id' => $owner->id, 'report_type' => 'sales', 'date_range' => 'week', 'status' => 'generated',
            'generated_by' => User::factory()->create(['shop_owner_id' => $owner->id])->id, 'generated_at' => now(),
            'period_start' => now()->subWeek(), 'period_end' => now(),
            'report_data' => ['summary' => ['total_revenue' => '1000.00'], 'rows' => [['order_number' => 'ORD-OWNED', 'total_amount' => '1000.00']]],
        ], $overrides));
    }

    public function test_owner_downloads_completed_statuses_and_reconstructs_missing_snapshot_file(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        foreach (['generated', 'reviewed', 'sent'] as $status) {
            $report = $this->report($owner, ['status' => $status]);
            $this->actingAs($owner, 'shop_owner')->get('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')
                ->assertOk()->assertDownload();
            $this->assertNotNull($report->fresh()->downloaded_at);
            $this->assertSame($status, $report->fresh()->status);
            $this->assertNotNull($report->fresh()->file_path);
        }
    }

    public function test_owner_cannot_read_foreign_or_missing_report_and_anonymous_cannot_download(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $other = $this->owner();
        $foreign = $this->report($other);
        $this->getJson('/api/shop-owner/erp/manager/reports/'.$foreign->id.'/download')->assertUnauthorized();
        $this->actingAs($owner, 'shop_owner')->getJson('/api/shop-owner/erp/manager/reports/'.$foreign->id.'/download')->assertNotFound();
        $this->getJson('/api/shop-owner/erp/manager/reports/999999/download')->assertNotFound();
        $this->getJson('/api/shop-owner/erp/manager/reports/not-a-number/download')->assertNotFound();
        $this->assertNull($foreign->fresh()->downloaded_at);
        $this->assertNull($foreign->fresh()->file_path);
    }

    public function test_owner_cannot_download_failed_or_invalid_snapshot_or_generate_or_review(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        foreach ([['status' => 'failed'], ['report_data' => null], ['report_data' => ['summary' => [], 'rows' => 'invalid']]] as $overrides) {
            $report = $this->report($owner, $overrides);
            $this->actingAs($owner, 'shop_owner')->getJson('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')->assertNotFound();
            $this->assertNull($report->fresh()->downloaded_at);
        }
        $this->actingAs($owner, 'shop_owner')->postJson('/api/manager/reports/generate', ['report_type' => 'sales', 'date_range' => 'week'])->assertForbidden();
        $this->postJson('/api/manager/reports/1/review', ['notes' => 'Forbidden owner review'])->assertForbidden();
        $this->assertDatabaseMissing('manager_reports', ['status' => 'reviewed']);
    }

    public function test_download_service_rejects_failed_snapshot_before_reconstruction_or_telemetry(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $report = $this->report($owner, ['status' => 'failed']);
        try {
            app(\App\Services\Manager\ManagerReportService::class)->reportForDownload($owner->id, $report->id);
            $this->fail('Failed reports must not be reconstructed or downloaded.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertNull($report->fresh()->downloaded_at);
            $this->assertNull($report->fresh()->file_path);
        }
    }

    public function test_download_service_rejects_a_foreign_private_path_before_telemetry(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $other = $this->owner();
        $path = 'manager-reports/'.$other->id.'/foreign.csv';
        Storage::disk('local')->put($path, 'FOREIGN PRIVATE CONTENT');
        $report = $this->report($owner, ['file_path' => $path]);
        try {
            app(\App\Services\Manager\ManagerReportService::class)->reportForDownload($owner->id, $report->id);
            $this->fail('An owned report row must not authorize a foreign file path.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertNull($report->fresh()->downloaded_at);
        }
    }

    public function test_owned_report_does_not_serve_foreign_or_traversal_file_content(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $other = $this->owner();
        $path = 'manager-reports/'.$other->id.'/foreign.csv';
        Storage::disk('local')->put($path, 'FOREIGN PRIVATE CONTENT');
        $report = $this->report($owner, ['file_path' => $path]);
        $this->actingAs($owner, 'shop_owner')->getJson('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')->assertNotFound();
        $this->assertNull($report->fresh()->downloaded_at);
        $report->update(['file_path' => 'manager-reports/'.$owner->id.'/../'.$other->id.'/foreign.csv']);
        $this->getJson('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')->assertNotFound();
        Storage::disk('local')->assertExists($path);
    }

    public function test_owner_page_projects_only_the_approved_download_get_url(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'shop_owner')->get(route('shop-owner.erp.manager.reports'))
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('ERP/Manager/Reports', false)
                ->where('erpCapabilities', fn ($caps): bool => ($caps['GET:api.manager.reports.download']['allowed'] ?? false) === true
                    && str_contains($caps['GET:api.manager.reports.download']['url'] ?? '', '/api/shop-owner/erp/manager/reports/__ERP_PARAM_id__/download')
                    && ($caps['POST:api.manager.reports.generate']['allowed'] ?? false) === false));
    }

    public function test_download_retains_core_read_classification_and_get_only_owner_contract(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $report = $this->report($owner);
        ShopOwnerModule::where('shop_owner_id', $owner->id)->update(['enabled' => false]);
        $this->actingAs($owner, 'shop_owner')->getJson('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')->assertOk();
        $entry = config('shop_modules.routes')['shop-owner.erp.api.manager.reports.download'] ?? null;
        $this->assertIsArray($entry);
        $this->assertSame('core', $entry['classification']);
        $this->assertSame([], $entry['module_keys']);
        $this->assertSame(['GET'], $entry['methods']);
        $this->assertSame('shop_owner', $entry['audience']);
        $this->assertSame('allowed', $entry['owner_access']);
    }
}
