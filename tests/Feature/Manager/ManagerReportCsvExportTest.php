<?php

namespace Tests\Feature\Manager;

use App\Models\ManagerReport;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Manager\ManagerReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManagerReportCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(array $rows, string $type = 'sales', array $summary = []): ManagerReport
    {
        Storage::fake('local');
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        $actor = User::factory()->create(['shop_owner_id' => $owner->id, 'role' => 'Manager']);
        return ManagerReport::create([
            'shop_owner_id' => $owner->id, 'report_type' => $type, 'date_range' => 'week', 'status' => 'reviewed',
            'generated_by' => $actor->id, 'generated_at' => now(), 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            'period_start' => now()->subWeek(), 'period_end' => now(),
            'report_data' => ['summary' => $summary, 'rows' => $rows],
        ]);
    }

    private function download(ManagerReport $report): array
    {
        $response = $this->actingAs(ShopOwner::findOrFail($report->shop_owner_id), 'shop_owner')
            ->get('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')->assertOk()->assertDownload();
        $stream = fopen($response->baseResponse->getFile()->getPathname(), 'r');
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    public function test_formatted_download_has_utf8_signature_for_spreadsheet_auto_detection(): void
    {
        $report = $this->snapshot([[
            'order_number' => 'ORD-UTF8', 'customer_name' => 'José',
            'total_amount' => '6000.25',
            'order_items' => [['product_name' => 'Nike Shoes', 'quantity' => 1, 'subtotal' => '6000.25']],
        ]]);

        $response = $this->actingAs(ShopOwner::findOrFail($report->shop_owner_id), 'shop_owner')
            ->get('/api/shop-owner/erp/manager/reports/'.$report->id.'/download')
            ->assertOk()->assertDownload();
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('José', $content);
        $this->assertStringContainsString('Nike Shoes × 1 — ₱6,000.25', $content);
    }

    public function test_historical_sales_export_is_readable_and_preserves_original_snapshot_file_and_decisions(): void
    {
        $report = $this->snapshot([[
            'order_id' => 99, 'order_number' => 'ORD-OLD', 'customer_name' => "José, \"Customer\"\nSecond line",
            'assigned_staff_id' => 123, 'assigned_staff_name' => 'Historical Staff', 'status' => 'completed',
            'total_amount' => '6000.25', 'order_items' => [
                ['product_name' => 'Nike Shoes', 'quantity' => 1, 'subtotal' => '6000.00'],
                ['product_name' => 'Laces, "white"', 'quantity' => 2, 'subtotal' => '0.25'],
            ], 'created_at' => '2026-09-30 12:30:00',
        ]], 'sales', ['total_revenue' => '6000.25']);
        $originalPath = 'manager-reports/'.$report->shop_owner_id.'/original.csv';
        Storage::disk('local')->put($originalPath, 'ORIGINAL RAW JSON ARTIFACT');
        $report->update(['file_path' => $originalPath]);
        $before = $report->fresh()->toArray();
        $csv = $this->download($report);
        $this->assertContains(['Total Revenue', '6000.25'], $csv);
        $this->assertContains(['Order Number', 'Customer', 'Assigned Staff', 'Status', 'Total Amount', 'Order Items', 'Created At'], $csv);
        $this->assertContains(['ORD-OLD', "José, \"Customer\"\nSecond line", 'Historical Staff', 'Completed', '6000.25',
            'Nike Shoes × 1 — ₱6,000.00; Laces, "white" × 2 — ₱0.25', '2026-09-30 12:30:00'], $csv);
        $after = $report->fresh()->toArray();
        foreach (['report_data', 'file_path', 'status', 'reviewed_by', 'reviewed_at', 'generated_at'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->assertSame('ORIGINAL RAW JSON ARTIFACT', Storage::disk('local')->get($originalPath));
        $this->assertNotNull($report->fresh()->downloaded_at);
        $this->assertSame($csv, $this->download($report));
        $this->assertCount(2, Storage::disk('local')->allFiles('manager-reports/'.$report->shop_owner_id));
    }

    public function test_formula_text_is_escaped_but_numeric_money_is_preserved(): void
    {
        $rows = [];
        foreach (['=HYPERLINK("evil")', '+cmd', '-cmd', '@SUM(1)', "\t =cmd", "\r\n+cmd"] as $value) {
            $rows[] = ['order_number' => 'SAFE', 'customer_name' => $value, 'total_amount' => '-10.25',
                'order_items' => [['product_name' => $value, 'quantity' => 1, 'subtotal' => '10.25']]];
        }
        $report = $this->snapshot($rows);
        $csv = $this->download($report);
        foreach (array_slice($csv, 4) as $index => $row) {
            $this->assertSame("'".$rows[$index]['customer_name'], $row[1]);
            $this->assertSame('-10.25', $row[4]);
            $this->assertStringStartsWith("'", $row[5]);
        }
    }

    public function test_legacy_item_shapes_empty_values_and_same_shop_assignee_fallback(): void
    {
        $report = $this->snapshot([
            ['order_number' => 'LEGACY', 'assigned_staff_id' => 0, 'order_items' => '[{"name":"Old shoe","qty":2,"total":"1234.56"}]'],
            ['order_number' => 'EMPTY', 'assigned_staff_id' => null, 'order_items' => null],
            ['order_number' => 'MULTI', 'order_items' => []],
        ]);
        $staff = User::factory()->create(['shop_owner_id' => $report->shop_owner_id, 'name' => 'Own Staff']);
        $foreign = User::factory()->create(['name' => 'FOREIGN PRIVATE NAME']);
        $data = $report->report_data;
        $data['rows'][0]['assigned_staff_id'] = $staff->id;
        $data['rows'][2]['assigned_staff_id'] = $foreign->id;
        $report->update(['report_data' => $data]);
        $csv = $this->download($report);
        $this->assertSame('Own Staff', $csv[4][2]);
        $this->assertSame('Old shoe × 2 — ₱1,234.56', $csv[4][5]);
        $this->assertSame('Unassigned', $csv[5][2]);
        $this->assertSame('', $csv[5][5]);
        $this->assertSame('Unknown staff', $csv[6][2]);
        $this->assertStringNotContainsString('FOREIGN PRIVATE NAME', json_encode($csv));
        $this->assertSame($data, $report->fresh()->report_data);
    }

    public function test_other_report_types_keep_readable_supported_fields_and_nested_values_without_json(): void
    {
        foreach (['stock', 'damaged', 'missing', 'performance'] as $type) {
            $report = $this->snapshot([['item_id' => 999, 'name' => 'Stock, "A"', 'price' => '10.25',
                'available_quantity' => 2, 'details' => ['reason' => 'Damaged', 'tags' => ['wet', 'torn']]]], $type);
            $csv = $this->download($report);
            $this->assertContains(['Name', 'Price', 'Available Quantity', 'Details'], $csv);
            $this->assertContains(['Stock, "A"', '10.25', '2', 'Reason: Damaged; Tags: wet; torn'], $csv);
        }
    }

    public function test_empty_report_has_readable_summary_and_no_records_message(): void
    {
        $report = $this->snapshot([], 'sales', ['total_orders' => 0]);
        $csv = $this->download($report);
        $this->assertContains(['Total Orders', '0'], $csv);
        $this->assertContains(['No records found for selected date range'], $csv);
    }

    public function test_new_sales_snapshot_freezes_assignee_and_financial_values_for_later_download(): void
    {
        Storage::fake('local');
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        $manager = User::factory()->create(['shop_owner_id' => $owner->id, 'role' => 'Manager']);
        $staff = User::factory()->create(['shop_owner_id' => $owner->id, 'name' => 'Original Staff']);
        $order = \App\Models\Order::factory()->create(['shop_owner_id' => $owner->id,
            'order_number' => 'ORD-SNAPSHOT', 'assigned_staff_id' => $staff->id, 'total_amount' => 6000,
            'customer_name' => 'Original Customer', 'status' => 'delivered']);
        $item = $order->items()->create(['product_name' => 'Nike Shoes', 'quantity' => 1,
            'price' => 6000, 'subtotal' => 6000]);
        $report = app(ManagerReportService::class)->generate($owner->id, $manager, 'sales', 'week');
        $this->assertSame('Original Staff', $report->report_data['rows'][0]['assigned_staff_name']);
        $original = Storage::disk('local')->get($report->file_path);
        $this->assertStringContainsString('Nike Shoes × 1 — ₱6,000.00', $original);
        $before = $report->report_data;
        $staff->update(['name' => 'Changed Staff']);
        $order->update(['total_amount' => 100, 'customer_name' => 'Changed Customer']);
        $item->update(['product_name' => 'Changed Shoe', 'subtotal' => 100]);
        $csv = $this->download($report);
        $this->assertContains(['ORD-SNAPSHOT', 'Original Customer', 'Original Staff', 'Delivered', '6000.00',
            'Nike Shoes × 1 — ₱6,000.00', $before['rows'][0]['created_at']], $csv);
        $this->assertSame($before, $report->fresh()->report_data);
        $this->assertSame($original, Storage::disk('local')->get($report->file_path));
    }

    public function test_missing_historical_original_does_not_replace_its_saved_path(): void
    {
        $report = $this->snapshot([['order_number' => 'MISSING-ORIGINAL', 'order_items' => []]]);
        $path = 'manager-reports/'.$report->shop_owner_id.'/historical-original.csv';
        $report->update(['file_path' => $path]);
        $csv = $this->download($report);
        $this->assertSame($path, $report->fresh()->file_path);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('MISSING-ORIGINAL', $csv[4][0]);
        $this->assertCount(1, Storage::disk('local')->allFiles('manager-reports/'.$report->shop_owner_id));
    }
}
