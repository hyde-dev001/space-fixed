<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ReviewReport;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class FlaggedAccountWorkflowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_only_pending_reports_enter_investigation_and_only_investigated_reports_resolve(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/flagged-accounts/{$report->id}/dismiss", [
            'admin_notes' => 'Not investigated yet.',
        ])->assertStatus(409);

        $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Not investigated yet.',
        ])->assertStatus(409);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")
            ->assertOk()
            ->assertJson(['status' => 'under_investigation', 'changed' => true]);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")
            ->assertOk()
            ->assertJson(['status' => 'under_investigation', 'changed' => false]);

        $this->postJson("/admin/flagged-accounts/{$report->id}/dismiss", [
            'admin_notes' => 'Reviewed and dismissed.',
        ])->assertOk()->assertJson(['status' => 'dismissed', 'changed' => true]);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")
            ->assertStatus(409);

        $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Attempted reopen.',
        ])->assertStatus(409);
    }

    public function test_legacy_banned_rows_are_exposed_as_account_suspended(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer, ['status' => ReviewReport::STATUS_LEGACY_BANNED]);
        $this->actingAsCompletedPrivileged($admin);

        $response = $this->get('/admin/flagged-accounts');
        $response->assertOk();

        $payload = $this->extractInertiaPageData($response->getContent());
        $account = collect($payload['props']['flaggedAccounts'] ?? [])
            ->firstWhere('id', (string) $report->id);

        $this->assertSame(ReviewReport::STATUS_ACCOUNT_SUSPENDED, $account['status'] ?? null);
    }

    public function test_suspension_uses_one_current_shared_identity_and_identical_retry_is_idempotent(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")->assertOk();
        $first = $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Customer review manipulation confirmed.',
        ]);

        $first->assertOk()->assertJson([
            'status' => ReviewReport::STATUS_ACCOUNT_SUSPENDED,
            'changed' => true,
        ]);
        DB::commit();

        $customer = $customer->fresh();
        $suspension = AccountSuspension::query()->sole();

        $this->assertSame('suspended', $customer->getRawOriginal('status'));
        $this->assertSame($suspension->id, (int) $customer->current_suspension_id);
        $this->assertSame(1, SuspensionAppeal::query()->count());
        $this->assertSame($suspension->id, (int) SuspensionAppeal::query()->value('suspension_id'));

        $second = $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Customer review manipulation confirmed.',
        ]);

        $second->assertOk()->assertJson([
            'status' => ReviewReport::STATUS_ACCOUNT_SUSPENDED,
            'changed' => false,
        ]);
        $this->assertDatabaseCount('account_suspensions', 1);
        $this->assertDatabaseCount('suspension_appeals', 1);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($customer, $suspension): bool {
            return $job->deliveryType === PrivilegedDeliveryType::CUSTOMER_SUSPENSION_NOTICE
                && $job->recipientType === 'user'
                && $job->recipientId === $customer->id
                && $job->businessEventId === 'account-suspension:'.$suspension->id.':notice';
        });
    }

    public function test_conflicting_terminal_retry_and_inactive_customer_are_rejected_without_mutation(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")->assertOk();
        $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Original reason.',
        ])->assertOk();

        $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Conflicting reason.',
        ])->assertStatus(409);

        $inactiveCustomer = $this->activePhaseTwoUser(['status' => 'inactive']);
        $inactiveReport = $this->flaggedReport($inactiveCustomer);
        $this->postJson("/admin/flagged-accounts/{$inactiveReport->id}/mark-reviewed")->assertOk();

        $this->postJson("/admin/flagged-accounts/{$inactiveReport->id}/ban", [
            'admin_notes' => 'Inactive customer cannot receive a new suspension.',
        ])->assertStatus(409);

        $this->assertSame('under_investigation', $inactiveReport->fresh()->status);
        $this->assertSame('inactive', $inactiveCustomer->fresh()->getRawOriginal('status'));
        $this->assertDatabaseCount('account_suspensions', 1);
    }

    public function test_ambiguous_linked_employee_rolls_back_report_customer_and_suspension(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer);
        Employee::factory()->active()->create(['email' => $customer->email]);
        Employee::factory()->active()->create(['email' => $customer->email]);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")->assertOk();
        $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Employee ambiguity rollback.',
        ])->assertStatus(409);

        $this->assertSame('under_investigation', $report->fresh()->status);
        $this->assertSame('active', $customer->fresh()->getRawOriginal('status'));
        $this->assertDatabaseCount('account_suspensions', 0);
        $this->assertDatabaseCount('suspension_appeals', 0);
    }

    public function test_audit_failure_rolls_back_the_complete_flagged_account_decision(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")->assertOk();

        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('flaggedAccountModerated')->once()->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(PrivilegedAudit::class, $audit);

        $this->postJson("/admin/flagged-accounts/{$report->id}/ban", [
            'admin_notes' => 'Audit rollback.',
        ])->assertStatus(500);

        $this->assertSame('under_investigation', $report->fresh()->status);
        $this->assertSame('active', $customer->fresh()->getRawOriginal('status'));
        $this->assertDatabaseCount('account_suspensions', 0);
        $this->assertDatabaseCount('suspension_appeals', 0);
        Queue::assertNothingPushed();
    }

    public function test_regular_admin_can_moderate_flagged_accounts(): void
    {
        $admin = $this->phaseTwoAdmin();
        $customer = $this->activePhaseTwoUser();
        $report = $this->flaggedReport($customer);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/flagged-accounts/{$report->id}/mark-reviewed")
            ->assertOk();
        $this->postJson("/admin/flagged-accounts/{$report->id}/dismiss", [
            'admin_notes' => 'Admin moderation is permitted.',
        ])->assertOk();

        $this->assertSame(SuperAdmin::ROLE_ADMIN, $admin->role);
        $this->assertSame('dismissed', $report->fresh()->status);
    }

    private function flaggedReport(User $customer, array $attributes = []): ReviewReport
    {
        $shop = ShopOwner::factory()->approved()->create();

        return ReviewReport::query()->create(array_merge([
            'review_type' => 'product',
            'review_id' => 1,
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'reason' => 'fake_review',
            'notes' => 'Flagged account workflow fixture.',
            'review_snapshot' => ['fixture' => true],
            'status' => ReviewReport::STATUS_PENDING_REVIEW,
        ], $attributes));
    }

    private function extractInertiaPageData(string $html): array
    {
        preg_match('/data-page="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'Unable to locate Inertia data-page payload.');

        $decoded = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $page = json_decode($decoded, true);

        $this->assertIsArray($page);

        return $page;
    }
}
