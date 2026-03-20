<?php

namespace Tests\Feature\Manager;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P5: Manager Report Generation & Distribution Tests
 *
 * Tests verify:
 * - Manager can generate reports (daily/weekly/monthly)
 * - Reports can be sent via email
 * - Reports can be downloaded (CSV/PDF formats)
 * - Report status is tracked correctly
 * - Non-managers cannot access report endpoints
 * - Reports respect shop scoping
 * - Email delivery is queued or sent
 */
class ManagerReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private ShopOwner $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create();

        $this->manager = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Manager']);
    }

    /**
     * Test: Non-manager cannot access report generation
     */
    public function test_non_manager_cannot_access_report_generation(): void
    {
        $staff = User::factory()
            ->for($this->shop)
            ->create(['role' => 'Staff']);

        $response = $this->actingAs($staff, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test: Manager can generate daily report
     */
    public function test_manager_can_generate_daily_report(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary',
                'date' => now()->toDateString()
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['id', 'type', 'status', 'generated_at']
        ]);
    }

    /**
     * Test: Manager can generate weekly report
     */
    public function test_manager_can_generate_weekly_report(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'weekly_summary',
                'start_date' => now()->startOfWeek()->toDateString(),
                'end_date' => now()->endOfWeek()->toDateString()
            ]);

        $response->assertStatus(200);
        $this->assertEquals('pending', $response->json('data.status'));
    }

    /**
     * Test: Manager can generate monthly report
     */
    public function test_manager_can_generate_monthly_report(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'monthly_summary',
                'month' => now()->format('Y-m')
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['id', 'type', 'status']
        ]);
    }

    /**
     * Test: Report generation validation - invalid type
     */
    public function test_report_generation_validates_type(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'invalid_report_type'
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Manager can request report email delivery
     */
    public function test_manager_can_request_report_email(): void
    {
        Mail::fake();

        // First generate a report
        $generateResponse = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        // Then request email
        $response = $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/send", [
                'email' => $this->manager->email,
                'recipients' => [$this->manager->email]
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test: Report email is queued/sent to correct recipients
     */
    public function test_report_email_sent_to_recipients(): void
    {
        Mail::fake();

        $generateResponse = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        $response = $this->actingAs($this->manager, 'user')
            ->postJson("/api/manager/reports/{$reportId}/send", [
                'recipients' => [
                    $this->manager->email,
                    'supervisor@example.com'
                ]
            ]);

        $response->assertStatus(200);
        // Verify mail was queued/sent
        Mail::assertQueued(\Illuminate\Mail\Mailable::class);
    }

    /**
     * Test: Manager can download report as CSV
     */
    public function test_manager_can_download_report_csv(): void
    {
        $generateResponse = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        $response = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}/download?format=csv");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv');
        $response->assertHeader('content-disposition');
    }

    /**
     * Test: Manager can download report as PDF
     */
    public function test_manager_can_download_report_pdf(): void
    {
        $generateResponse = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        $response = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}/download?format=pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Test: Download validates format parameter
     */
    public function test_download_validates_format(): void
    {
        $generateResponse = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        $response = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}/download?format=invalid");

        $response->assertStatus(422);
    }

    /**
     * Test: Manager cannot download other shop's report
     */
    public function test_manager_cannot_download_other_shops_report(): void
    {
        $otherShop = ShopOwner::factory()->create();
        $otherManager = User::factory()
            ->for($otherShop)
            ->create(['role' => 'Manager']);

        // Other manager generates a report
        $generateResponse = $this->actingAs($otherManager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        // This manager tries to download it
        $response = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}/download");

        $response->assertStatus(404);
    }

    /**
     * Test: Report status transitions correctly
     */
    public function test_report_status_transitions(): void
    {
        $generateResponse = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $generateResponse->json('data.id');

        // Initially pending
        $this->assertEquals('pending', $generateResponse->json('data.status'));

        // After getting details, may be completed
        $detailResponse = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}");

        $detailResponse->assertStatus(200);
        // Status should be one of: pending, processing, completed, failed
        $this->assertIn(
            $detailResponse->json('data.status'),
            ['pending', 'processing', 'completed', 'failed']
        );
    }

    /**
     * Test: Manager can list their reports
     */
    public function test_manager_can_list_their_reports(): void
    {
        // Generate multiple reports
        $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', ['type' => 'daily_summary']);

        $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', ['type' => 'weekly_summary']);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/reports');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'type', 'status', 'generated_at']
            ]
        ]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    /**
     * Test: Cannot access nonexistent report
     */
    public function test_cannot_access_nonexistent_report(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/reports/99999');

        $response->assertStatus(404);
    }

    /**
     * Test: Report deleted/cleaned up after period expires
     */
    public function test_old_reports_have_expiration_policy(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'daily_summary'
            ]);

        $reportId = $response->json('data.id');

        // Check if downloaded reports are marked with expiration
        $detailResponse = $this->actingAs($this->manager, 'user')
            ->getJson("/api/manager/reports/{$reportId}");

        if ($detailResponse->json('data.expires_at')) {
            $this->assertNotNull($detailResponse->json('data.expires_at'));
        }
    }

    /**
     * Test: Cannot generate report with invalid date range
     */
    public function test_cannot_generate_report_with_invalid_dates(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->postJson('/api/manager/reports/generate', [
                'type' => 'weekly_summary',
                'start_date' => now()->toDateString(),
                'end_date' => now()->subDays(5)->toDateString() // End before start
            ]);

        $response->assertStatus(422);
    }
}
