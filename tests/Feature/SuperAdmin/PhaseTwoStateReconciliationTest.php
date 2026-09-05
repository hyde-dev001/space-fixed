<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\AccountSuspension;
use App\Models\AuditLog;
use App\Models\ShopOwner;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\PhaseTwoStateReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PhaseTwoStateReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_prints_proposed_counts(): void
    {
        $user = User::factory()->create([
            'status' => 'suspended',
        ]);

        $beforeSuspensions = AccountSuspension::query()->count();
        $beforeActivities = Activity::query()->count();

        $output = $this->runReconciliation();

        $this->assertStringContainsString('Mode: dry-run', $output);
        $this->assertStringContainsString('Suspensions: proposed=1', $output);
        $this->assertStringContainsString('Operator review required: 1', $output);
        $this->assertSame($beforeSuspensions, AccountSuspension::query()->count());
        $this->assertSame($beforeActivities, Activity::query()->count());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'suspended',
            'current_suspension_id' => null,
        ]);
    }

    public function test_suspended_account_without_a_live_appeal_gets_unattributed_legacy_suspension(): void
    {
        $user = User::factory()->create([
            'status' => 'suspended',
        ]);

        $this->runReconciliation(apply: true);

        $suspension = AccountSuspension::query()->sole();

        $this->assertSame(AccountSuspension::ACCOUNT_TYPE_CUSTOMER, $suspension->account_type);
        $this->assertSame($user->id, $suspension->account_id);
        $this->assertSame(AccountSuspension::SOURCE_LEGACY_RECONCILIATION, $suspension->source);
        $this->assertNull($suspension->reason);
        $this->assertNull($suspension->suspended_by_super_admin_id);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'current_suspension_id' => $suspension->id,
        ]);

        $this->assertSame(1, Activity::query()
            ->where('event', 'legacy_account_suspension_reconciled')
            ->count());
    }

    public function test_one_live_appeal_is_linked_without_fabricating_a_second_appeal(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create([
            'status' => 'suspended',
        ]);
        $appeal = $this->createAppeal('customer', $user->id, [
            'suspension_reason' => 'Reason preserved from the unique appeal.',
            'suspended_by_super_admin_id' => $admin->id,
        ]);

        $this->runReconciliation(apply: true);

        $suspension = AccountSuspension::query()->sole();

        $this->assertSame('Reason preserved from the unique appeal.', $suspension->reason);
        $this->assertSame($admin->id, $suspension->suspended_by_super_admin_id);
        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $appeal->id,
            'status' => 'eligible',
            'suspension_id' => $suspension->id,
        ]);
        $this->assertSame(1, SuspensionAppeal::query()->count());
    }

    public function test_multiple_live_appeals_are_superseded_and_reported_for_operator_review(): void
    {
        $user = User::factory()->create([
            'status' => 'suspended',
        ]);
        $first = $this->createAppeal('customer', $user->id);
        $second = $this->createAppeal('customer', $user->id, [
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
        ]);

        $output = $this->runReconciliation(apply: true);

        $this->assertStringContainsString('Operator review required: 1', $output);
        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $first->id,
            'status' => 'superseded',
            'suspension_id' => null,
        ]);
        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $second->id,
            'status' => 'superseded',
            'suspension_id' => null,
        ]);
        $this->assertSame(1, AccountSuspension::query()->count());
        $this->assertSame(2, Activity::query()
            ->where('event', 'legacy_appeal_superseded')
            ->count());
    }

    public function test_operational_account_live_appeals_are_superseded_without_creating_a_suspension(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $appeal = $this->createAppeal('customer', $user->id);

        $this->runReconciliation(apply: true);

        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $appeal->id,
            'status' => 'superseded',
            'suspension_id' => null,
        ]);
        $this->assertDatabaseCount('account_suspensions', 0);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'active',
            'current_suspension_id' => null,
        ]);
    }

    public function test_expired_appeals_are_expired_before_live_appeal_attribution_and_terminal_appeals_are_unchanged(): void
    {
        $shop = ShopOwner::factory()->create([
            'status' => 'suspended',
            'suspension_reason' => 'Legacy shop suspension reason.',
        ]);
        $expired = $this->createAppeal('shop_owner', $shop->id, [
            'expires_at' => now()->subMinute(),
        ]);
        $terminal = $this->createAppeal('shop_owner', $shop->id, [
            'status' => 'approved',
            'expires_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
        ]);

        $this->runReconciliation(apply: true);

        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $expired->id,
            'status' => 'expired',
            'suspension_id' => null,
        ]);
        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $terminal->id,
            'status' => 'approved',
            'suspension_id' => null,
        ]);
        $this->assertDatabaseHas('shop_owners', [
            'id' => $shop->id,
            'status' => 'suspended',
        ]);
        $this->assertSame(1, AccountSuspension::query()->count());
        $this->assertSame(0, Activity::query()
            ->where('event', 'legacy_appeal_superseded')
            ->count());
    }

    public function test_legacy_warning_audits_become_deterministic_compatibility_actions(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->approved()->create();
        $later = $this->createLegacyWarning($shop->id, $admin->id, now()->subDay());
        $earlier = $this->createLegacyWarning($shop->id, $admin->id, now()->subDays(2));
        $sameTime = $this->createLegacyWarning($shop->id, $admin->id, $earlier->created_at);

        $this->runReconciliation(apply: true);

        $actions = ShopReportModerationAction::query()
            ->where('shop_owner_id', $shop->id)
            ->orderBy('warning_strike_number')
            ->get();

        $this->assertCount(3, $actions);
        $this->assertSame([$earlier->id, $sameTime->id, $later->id], $actions->pluck('legacy_audit_log_id')->all());
        $this->assertSame([1, 2, 3], $actions->pluck('warning_strike_number')->all());
        $this->assertSame([[], [], []], $actions->pluck('report_ids')->all());
        $this->assertSame(
            [
                AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
                AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
                AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
            ],
            $actions->pluck('source')->all(),
        );
        $this->assertSame(3, $actions->pluck('warning_strike_number')->unique()->count());
        $this->assertSame(3, Activity::query()
            ->where('event', 'legacy_warning_strike_reconciled')
            ->count());
    }

    public function test_second_apply_is_idempotent_and_uses_a_new_server_operation_uuid(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create(['status' => 'suspended']);
        $this->createAppeal('customer', $user->id);
        $shop = ShopOwner::factory()->approved()->create();
        $this->createLegacyWarning($shop->id, $admin->id, now());

        $firstOutput = $this->runReconciliation(apply: true);
        $firstCounts = [
            AccountSuspension::query()->count(),
            SuspensionAppeal::query()->count(),
            ShopReportModerationAction::query()->count(),
            Activity::query()->whereIn('event', [
                'legacy_account_suspension_reconciled',
                'legacy_appeal_superseded',
                'legacy_warning_strike_reconciled',
            ])->count(),
        ];

        $secondOutput = $this->runReconciliation(apply: true);
        $secondCounts = [
            AccountSuspension::query()->count(),
            SuspensionAppeal::query()->count(),
            ShopReportModerationAction::query()->count(),
            Activity::query()->whereIn('event', [
                'legacy_account_suspension_reconciled',
                'legacy_appeal_superseded',
                'legacy_warning_strike_reconciled',
            ])->count(),
        ];

        $this->assertSame($firstCounts, $secondCounts);
        $this->assertStringContainsString('created=0', $secondOutput);
        $this->assertNotSame($this->operationUuidFrom($firstOutput), $this->operationUuidFrom($secondOutput));
    }

    public function test_command_has_only_apply_as_a_mutating_option_and_generates_operation_uuid_server_side(): void
    {
        $exitCode = Artisan::call('super-admin:reconcile-phase-two-state', ['--help' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--apply', $output);
        $this->assertStringNotContainsString('--operation', $output);
        $this->assertStringNotContainsString('--correlation', $output);
    }

    public function test_chunk_boundaries_do_not_skip_modified_accounts(): void
    {
        User::factory()->count(5)->create(['status' => 'suspended']);

        $result = app(PhaseTwoStateReconciler::class)->reconcile(
            operationId: (string) Str::uuid(),
            apply: true,
            chunkSize: 2,
        );

        $this->assertSame(5, $result['suspensions_created']);
        $this->assertSame(5, AccountSuspension::query()
            ->where('source', AccountSuspension::SOURCE_LEGACY_RECONCILIATION)
            ->count());
    }

    public function test_failure_in_one_warning_aggregate_rolls_back_and_returns_the_aggregate_id(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $legacyAudit = $this->createLegacyWarning($shop->id, 999999, now());

        $exitCode = Artisan::call('super-admin:reconcile-phase-two-state', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString((string) $shop->id, $output);
        $this->assertStringContainsString((string) $legacyAudit->id, $output);
        $this->assertDatabaseCount('shop_report_moderation_actions', 0);
        $this->assertSame(0, Activity::query()
            ->where('event', 'legacy_warning_strike_reconciled')
            ->count());
    }

    public function test_reconciliation_audit_properties_are_bounded_and_use_the_server_operation_uuid(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create(['status' => 'suspended']);
        $appeal = $this->createAppeal('customer', $user->id, [
            'status' => 'submitted',
            'suspended_by_super_admin_id' => $admin->id,
            'appeal_message' => 'Do not copy this message into privileged audit metadata.',
            'recipient_email' => 'private@example.com',
        ]);

        $output = $this->runReconciliation(apply: true);
        $operationId = $this->operationUuidFrom($output);

        $activities = Activity::query()
            ->whereIn('event', [
                'legacy_account_suspension_reconciled',
                'legacy_appeal_superseded',
            ])
            ->get();

        $this->assertCount(1, $activities->where('event', 'legacy_account_suspension_reconciled'));
        $this->assertCount(0, $activities->where('event', 'legacy_appeal_superseded'));
        foreach ($activities as $activity) {
            $properties = $activity->properties->toArray();
            $this->assertSame($operationId, $properties['correlation_id']);
            $this->assertArrayNotHasKey('appeal_token', $properties);
            $this->assertArrayNotHasKey('recipient_email', $properties);
            $this->assertArrayNotHasKey('appeal_message', $properties);
            $this->assertStringNotContainsString($appeal->appeal_token, $activity->properties->toJson());
            $this->assertStringNotContainsString('private@example.com', $activity->properties->toJson());
        }

        $this->assertSame($admin->id, $appeal->suspended_by_super_admin_id);
    }

    private function runReconciliation(bool $apply = false): string
    {
        $outputBuffer = new BufferedOutput();
        $exitCode = Artisan::call(
            'super-admin:reconcile-phase-two-state',
            $apply ? ['--apply' => true] : [],
            $outputBuffer,
        );
        $output = $outputBuffer->fetch();

        $this->assertSame(0, $exitCode, $output);

        return $output;
    }

    /** @param array<string, mixed> $attributes */
    private function createAppeal(string $accountType, int $accountId, array $attributes = []): SuspensionAppeal
    {
        $defaults = [
            'account_type' => $accountType,
            'account_id' => $accountId,
            'account_name' => 'Phase Two Account',
            'recipient_email' => 'phase-two@example.com',
            'suspension_reason' => 'Legacy appeal reason.',
            'status' => 'eligible',
            'appeal_token' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addDay(),
        ];

        return SuspensionAppeal::query()->create(array_merge($defaults, $attributes));
    }

    private function createLegacyWarning(int $shopOwnerId, int $adminId, \DateTimeInterface $createdAt): AuditLog
    {
        $audit = AuditLog::query()->create([
            'shop_owner_id' => $shopOwnerId,
            'action' => 'shop_report_warn',
            'target_type' => 'ShopOwner',
            'target_id' => $shopOwnerId,
            'data' => [
                'requested_action' => 'warn',
                'applied_action' => 'warn',
                'admin_id' => $adminId,
                'report_count' => 2,
            ],
        ]);

        $audit->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $audit->fresh();
    }

    private function operationUuidFrom(string $output): string
    {
        preg_match('/Operation UUID:\s*([0-9a-f-]{36})/i', $output, $matches);

        $this->assertArrayHasKey(1, $matches, $output);
        $this->assertTrue(Str::isUuid($matches[1]));

        return $matches[1];
    }
}
