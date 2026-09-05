<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\AuditLog;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class LegacyPrivilegedAuditImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_has_legacy_provenance_and_query_indexes(): void
    {
        $this->assertTrue(Schema::hasColumn('activity_log', 'legacy_source'));
        $this->assertTrue(Schema::hasColumn('activity_log', 'legacy_id'));

        $indexNames = collect(Schema::getIndexes('activity_log'))
            ->pluck('name')
            ->all();

        $this->assertContains('activity_log_legacy_provenance_unique', $indexNames);
        $this->assertContains('activity_log_privileged_created_index', $indexNames);
        $this->assertContains('activity_log_privileged_event_index', $indexNames);
    }

    public function test_imports_every_allowlisted_legacy_action_with_safe_linkage(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create();
        $appeal = SuspensionAppeal::query()->create([
            'account_type' => 'customer',
            'account_id' => $user->id,
            'recipient_email' => 'appeal@example.test',
            'appeal_token' => Str::random(64),
            'status' => 'approved',
        ]);

        $common = [
            'actor_user_id' => $admin->id,
            'created_at' => '2026-08-01 12:00:00',
        ];

        $this->legacy(array_merge($common, [
            'action' => 'user_suspended',
            'target_type' => 'user',
            'target_id' => $user->id,
            'metadata' => ['email' => 'private@example.test', 'name' => 'Private Name'],
        ]));
        $this->legacy(array_merge($common, [
            'action' => 'user_activated',
            'target_type' => 'user',
            'target_id' => $user->id,
        ]));
        $this->legacy(array_merge($common, [
            'action' => 'shop_activated',
            'target_type' => 'shop_owner',
            'target_id' => $shop->id,
        ]));
        $this->legacy(array_merge($common, [
            'action' => 'shop_owner_approved',
            'target_type' => 'shop_owner',
            'target_id' => $shop->id,
        ]));

        foreach (['dismiss', 'warn', 'suspend'] as $outcome) {
            $this->legacy([
                'action' => 'shop_report_'.$outcome,
                'target_type' => 'ShopOwner',
                'target_id' => $shop->id,
                'shop_owner_id' => $shop->id,
                'data' => [
                    'requested_action' => $outcome,
                    'applied_action' => $outcome,
                    'admin_id' => $admin->id,
                    'report_count' => 2,
                    'admin_email' => 'do-not-import@example.test',
                    'notes' => 'do not import free-form notes',
                ],
            ]);
        }

        foreach (['approved', 'rejected'] as $decision) {
            $this->legacy(array_merge($common, [
                'action' => 'suspension_appeal_'.$decision,
                'target_type' => 'customer',
                'target_id' => $user->id,
                'data' => ['appeal_id' => $appeal->id, 'reviewer_notes' => 'private notes'],
            ]));
        }

        $result = $this->artisan('privileged-audit:import-legacy', [
            '--apply' => true,
            '--chunk' => 2,
        ]);

        $result->assertExitCode(0);
        $result->expectsOutput('imported=9');
        $result->run();
        $this->assertSame(9, Activity::query()->where('log_name', 'privileged')->count());
        $this->assertSame(1, Activity::query()->where('event', 'user_suspended')->count());
        $this->assertSame(1, Activity::query()->where('event', 'user_reactivated')->count());
        $this->assertSame(1, Activity::query()->where('event', 'shop_reactivated')->count());
        $this->assertSame(1, Activity::query()->where('event', 'shop_registration_approved')->count());
        $this->assertSame(3, Activity::query()->where('event', 'shop_reports_moderated')->count());
        $this->assertSame(2, Activity::query()->where('event', 'suspension_appeal_decided')->count());

        $activity = Activity::query()->where('event', 'user_suspended')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame(SuperAdmin::class, $activity->causer_type);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(User::class, $activity->subject_type);
        $this->assertSame($user->id, $activity->subject_id);
        $this->assertSame('legacy_import', $properties['source']);
        $this->assertSame('legacy_unknown', $properties['actor_role']);
        $this->assertFalse($properties['actor_role_verified']);
        $this->assertTrue(Str::isUuid($properties['correlation_id']));
        $this->assertArrayNotHasKey('email', $properties);
        $this->assertArrayNotHasKey('admin_email', $properties);
        $this->assertArrayNotHasKey('notes', $properties);
        $this->assertArrayNotHasKey('reviewer_notes', $properties);
    }

    public function test_preserves_timestamp_provenance_and_source_row(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->create();
        $legacy = $this->legacy([
            'actor_user_id' => $admin->id,
            'action' => 'shop_owner_approved',
            'target_type' => 'shop_owner',
            'target_id' => $shop->id,
            'metadata' => [
                'email' => 'private@example.test',
                'business_name' => 'Private Business',
            ],
            'created_at' => '2026-07-31 04:05:06',
        ]);

        $this->artisan('privileged-audit:import-legacy', ['--apply' => true])->assertExitCode(0)->run();

        $activity = Activity::query()->where('legacy_id', $legacy->id)->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame('2026-07-31 04:05:06', $activity->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('audit_logs', $activity->legacy_source);
        $this->assertSame($legacy->id, $activity->legacy_id);
        $this->assertSame('audit_logs', $properties['legacy_source']);
        $this->assertSame($legacy->id, $properties['legacy_id']);
        $this->assertTrue(DB::table('audit_logs')->where('id', $legacy->id)->exists());
        $this->assertArrayNotHasKey('email', $properties);
        $this->assertArrayNotHasKey('business_name', $properties);
    }

    public function test_defaults_to_dry_run_and_honors_bounded_limit_and_chunk(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->create();

        foreach (range(1, 3) as $number) {
            $this->legacy([
                'actor_user_id' => $admin->id,
                'action' => 'shop_activated',
                'target_type' => 'shop_owner',
                'target_id' => $shop->id,
                'created_at' => now()->subMinutes(3 - $number),
            ]);
        }

        $dryRun = $this->artisan('privileged-audit:import-legacy', [
            '--limit' => 1,
            '--chunk' => 1,
        ]);

        $dryRun->assertExitCode(0);
        $dryRun->expectsOutput('mode=dry_run');
        $dryRun->expectsOutput('imported=0');
        $dryRun->expectsOutput('would_import=1');
        $dryRun->run();
        $this->assertSame(0, Activity::query()->where('log_name', 'privileged')->count());

        $apply = $this->artisan('privileged-audit:import-legacy', [
            '--apply' => true,
            '--limit' => 1,
            '--chunk' => 1,
        ]);

        $apply->assertExitCode(0);
        $apply->expectsOutput('imported=1');
        $apply->run();
        $this->assertSame(1, Activity::query()->where('log_name', 'privileged')->count());
    }

    public function test_reruns_are_idempotent_and_phase_two_events_are_not_duplicated(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->create();
        $represented = $this->legacy([
            'actor_user_id' => $admin->id,
            'action' => 'shop_report_warn',
            'target_type' => 'ShopOwner',
            'target_id' => $shop->id,
            'shop_owner_id' => $shop->id,
            'data' => ['admin_id' => $admin->id, 'applied_action' => 'warn'],
        ]);
        Activity::query()->create([
            'log_name' => 'privileged',
            'description' => 'legacy_warning_strike_reconciled',
            'event' => 'legacy_warning_strike_reconciled',
            'properties' => ['legacy_audit_log_id' => $represented->id],
        ]);

        $importable = $this->legacy([
            'actor_user_id' => $admin->id,
            'action' => 'shop_activated',
            'target_type' => 'shop_owner',
            'target_id' => $shop->id,
        ]);

        $this->artisan('privileged-audit:import-legacy', ['--apply' => true])->assertExitCode(0)->run();
        $countAfterFirstRun = Activity::query()->where('log_name', 'privileged')->count();
        $this->assertSame(2, $countAfterFirstRun);

        $rerun = $this->artisan('privileged-audit:import-legacy', ['--apply' => true]);
        $rerun->assertExitCode(0);
        $rerun->expectsOutput('imported=0');
        $rerun->expectsOutput('already_imported=2');
        $rerun->run();
        $this->assertSame($countAfterFirstRun, Activity::query()->where('log_name', 'privileged')->count());
        $this->assertSame(1, Activity::query()->where('legacy_id', $importable->id)->count());
    }

    public function test_reports_each_skip_reason_without_exposing_metadata(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create();

        $this->legacy([
            'action' => 'user_deleted',
            'target_type' => 'user',
            'target_id' => 1,
        ]);
        $this->legacy([
            'action' => 'user_suspended',
            'target_type' => 'user',
            'target_id' => $user->id,
            'actor_user_id' => 999999,
        ]);
        $this->legacy([
            'action' => 'shop_activated',
            'target_type' => 'shop_owner',
            'target_id' => 999999,
            'actor_user_id' => $admin->id,
        ]);
        $this->legacy([
            'action' => 'user_activated',
            'target_type' => 'user',
            'target_id' => 1,
            'object_id' => 2,
            'actor_user_id' => $admin->id,
        ]);
        $this->legacy([
            'action' => 'shop_report_warn',
            'target_type' => 'ShopOwner',
            'target_id' => $shop->id,
            'shop_owner_id' => $shop->id,
            'actor_user_id' => $admin->id,
            'data' => ['admin_id' => $admin->id, 'applied_action' => 'unknown'],
        ]);
        $this->legacy([
            'action' => 'suspension_appeal_approved',
            'target_type' => 'customer',
            'target_id' => 1,
            'actor_user_id' => $admin->id,
            'data' => ['appeal_id' => 999999],
        ]);
        $this->insertMalformedAuditRow();

        $result = $this->artisan('privileged-audit:import-legacy', ['--chunk' => 1]);

        $result->assertExitCode(0);
        $result->expectsOutput('imported=0');
        $result->expectsOutput('skipped=7');
        $result->expectsOutputToContain('skipped_reason[action_not_allowlisted]=1');
        $result->expectsOutputToContain('skipped_reason[actor_unknown]=1');
        $result->expectsOutputToContain('skipped_reason[target_missing]=1');
        $result->expectsOutputToContain('skipped_reason[target_conflict]=1');
        $result->expectsOutputToContain('skipped_reason[report_outcome_unrecognized]=1');
        $result->expectsOutputToContain('skipped_reason[appeal_missing]=1');
        $result->expectsOutputToContain('skipped_reason[malformed_json]=1');
        $result->run();
        $this->assertSame(0, Activity::query()->where('log_name', 'privileged')->count());
    }

    public function test_known_actor_field_allows_user_and_super_admin_id_collision_but_ambiguous_target_is_skipped(): void
    {
        $user = User::factory()->create();
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shop = ShopOwner::factory()->create();

        $this->assertSame($user->id, $admin->id);

        $importable = $this->legacy([
            'actor_user_id' => $admin->id,
            'action' => 'user_suspended',
            'target_type' => 'user',
            'target_id' => $user->id,
        ]);
        $this->legacy([
            'actor_user_id' => $admin->id,
            'action' => 'user_activated',
            'target_type' => 'user',
            'target_id' => $user->id,
            'object_type' => 'ShopOwner',
            'object_id' => $shop->id,
        ]);

        $this->artisan('privileged-audit:import-legacy', ['--apply' => true])->assertExitCode(0)->run();

        $this->assertSame(1, Activity::query()->where('legacy_id', $importable->id)->count());
        $this->assertSame(1, Activity::query()->where('log_name', 'privileged')->count());
    }

    private function legacy(array $attributes): AuditLog
    {
        $audit = AuditLog::query()->create(array_merge([
            'user_id' => null,
            'shop_owner_id' => null,
            'actor_user_id' => null,
            'action' => 'unrelated',
            'object_type' => null,
            'object_id' => null,
            'target_type' => null,
            'target_id' => null,
            'data' => null,
            'metadata' => null,
        ], $attributes));

        if (array_key_exists('created_at', $attributes)) {
            $createdAt = $attributes['created_at'];
            $audit->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $audit->fresh();
    }

    private function insertMalformedAuditRow(): void
    {
        DB::table('audit_logs')->insert([
            'action' => 'user_suspended',
            'target_type' => 'user',
            'target_id' => 1,
            'actor_user_id' => 1,
            'data' => '{not-valid-json',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
