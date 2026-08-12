<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Mail\ShopReportWarningMail;
use App\Mail\SuspensionNoticeMail;
use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\ShopReportModerationAction;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class ShopReportModerationInvariantTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_decision_uses_the_exact_submitted_report_set(): void
    {
        Mail::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $reports = $this->openPhaseTwoShopReports($shop, 3);
        $this->actingAsCompletedPrivileged($admin);

        $response = $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [$reports[0]->id, $reports[1]->id],
            'admin_notes' => 'Reviewed exact report set.',
        ]);

        $response->assertOk()->assertJsonPath('action.report_ids', [$reports[0]->id, $reports[1]->id]);

        $this->assertSame('dismissed', $reports[0]->fresh()->status);
        $this->assertSame('dismissed', $reports[1]->fresh()->status);
        $this->assertSame('submitted', $reports[2]->fresh()->status);

        $action = ShopReportModerationAction::query()->sole();
        $this->assertSame([$reports[0]->id, $reports[1]->id], $action->report_ids);
        $this->assertNotSame('', (string) $action->decision_key);
        $this->assertNull($action->warning_strike_number);
        Mail::assertNothingSent();
    }

    public function test_same_set_same_outcome_is_idempotent_and_conflicting_outcome_is_rejected(): void
    {
        Mail::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $reports = $this->openPhaseTwoShopReports($shop, 2);
        $this->actingAsCompletedPrivileged($admin);

        $first = $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'warn',
            'report_ids' => [$reports[1]->id, $reports[0]->id],
            'admin_notes' => 'First warning decision.',
        ]);
        $second = $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'warn',
            'report_ids' => [$reports[0]->id, $reports[1]->id],
            'admin_notes' => 'Retry with different note.',
        ]);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(
            $first->json('action.id'),
            $second->json('action.id'),
        );
        $this->assertDatabaseCount('shop_report_moderation_actions', 1);
        Mail::assertSentCount(1);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [$reports[0]->id, $reports[1]->id],
            'admin_notes' => 'Conflicting decision.',
        ])->assertStatus(409);

        $this->assertDatabaseCount('shop_report_moderation_actions', 1);
        $this->assertSame('warned', $reports[0]->fresh()->status);
        $this->assertSame('warned', $reports[1]->fresh()->status);
    }

    public function test_warning_strikes_are_runtime_actions_and_the_third_warning_suspends_once(): void
    {
        Mail::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $this->actingAsCompletedPrivileged($admin);

        for ($strike = 1; $strike <= 3; $strike++) {
            $report = $this->openPhaseTwoShopReports($shop, 1)->sole();

            $this->postJson("/admin/shop-reports/{$shop->id}/action", [
                'action' => 'warn',
                'report_ids' => [$report->id],
                'admin_notes' => "Warning {$strike}",
            ])->assertOk();
        }

        $actions = ShopReportModerationAction::query()->orderBy('warning_strike_number')->get();
        $this->assertSame([1, 2, 3], $actions->pluck('warning_strike_number')->all());
        $this->assertSame(['warn', 'warn', 'suspend'], $actions->pluck('applied_action')->all());
        $this->assertSame('suspended', $shop->fresh()->getRawOriginal('status'));
        $this->assertNotNull($shop->fresh()->current_suspension_id);
        $this->assertSame(1, AccountSuspension::query()->count());
        Mail::assertSent(ShopReportWarningMail::class, 2);
        Mail::assertSent(SuspensionNoticeMail::class);
    }

    public function test_invalid_report_sets_and_client_decision_key_do_not_mutate(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $otherShop = $this->approvedPhaseTwoShop();
        $reports = $this->openPhaseTwoShopReports($shop, 2);
        $foreignReport = $this->openPhaseTwoShopReports($otherShop, 1)->sole();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [$reports[0]->id, $reports[0]->id],
        ])->assertStatus(422);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [$reports[0]->id, $foreignReport->id],
        ])->assertStatus(409);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [$reports[0]->id],
            'decision_key' => 'client-controlled',
        ])->assertStatus(422);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [],
        ])->assertStatus(422);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => range(1, 101),
        ])->assertStatus(422);

        $this->assertSame('submitted', $reports[0]->fresh()->status);
        $this->assertSame('submitted', $reports[1]->fresh()->status);
        $this->assertDatabaseCount('shop_report_moderation_actions', 0);
    }

    public function test_submitted_reports_follow_review_then_terminal_transition(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $report = $this->openPhaseTwoShopReports($shop, 1)->sole();
        $transitions = [];
        Event::listen('eloquent.updating: '.ShopReport::class, function (ShopReport $model) use (&$transitions): void {
            if ($model->isDirty('status')) {
                $transitions[] = [
                    (string) $model->getRawOriginal('status'),
                    (string) $model->getAttribute('status'),
                ];
            }
        });
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'dismiss',
            'report_ids' => [$report->id],
        ])->assertOk();

        $this->assertSame('dismissed', $report->fresh()->status);
        $this->assertSame([
            ['submitted', 'under_review'],
            ['under_review', 'dismissed'],
        ], $transitions);
    }

    public function test_audit_failure_rolls_back_reports_action_and_notifications(): void
    {
        Mail::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $report = $this->openPhaseTwoShopReports($shop, 1)->sole();
        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('shopReportsModerated')->once()->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(PrivilegedAudit::class, $audit);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'warn',
            'report_ids' => [$report->id],
            'admin_notes' => 'Rollback test',
        ])->assertStatus(500);

        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertSame('approved', $shop->fresh()->getRawOriginal('status'));
        $this->assertDatabaseCount('shop_report_moderation_actions', 0);
        Mail::assertNothingSent();
    }

    public function test_suspension_failure_rolls_back_reports_and_decision(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $report = $this->openPhaseTwoShopReports($shop, 1)->sole();
        Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $shop->email,
        ]);
        Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $shop->email,
        ]);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/shop-reports/{$shop->id}/action", [
            'action' => 'suspend',
            'report_ids' => [$report->id],
            'admin_notes' => 'Suspension rollback test',
        ])->assertStatus(409);

        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertSame('approved', $shop->fresh()->getRawOriginal('status'));
        $this->assertDatabaseCount('shop_report_moderation_actions', 0);
        $this->assertDatabaseCount('account_suspensions', 0);
    }
}
