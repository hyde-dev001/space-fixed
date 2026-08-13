<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedAuditHistoryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_audit_route_has_the_full_privileged_boundary(): void
    {
        $route = Route::getRoutes()->getByName('admin.audit');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('super_admin.auth', $route->middleware());
        $this->assertContains('privileged.active', $route->middleware());
        $this->assertContains('privileged.mfa', $route->middleware());
        $this->assertContains('privileged.capability:view_privileged_audit', $route->middleware());
    }

    public function test_audit_route_rejects_unauthenticated_mfa_incomplete_and_inactive_sessions(): void
    {
        $this->get('/admin/audit')->assertRedirect('/admin/login');

        $withoutMfa = SuperAdmin::factory()->activeWithoutMfa()->create();
        $this->actingAs($withoutMfa, 'super_admin')
            ->get('/admin/audit')
            ->assertRedirect('/admin/login');

        $inactive = SuperAdmin::factory()->mfaEnrolled()->inactive()->create();
        $this->actingAsCompletedPrivileged($inactive)
            ->getJson('/admin/audit')
            ->assertUnauthorized();
    }

    public function test_super_admin_sees_all_privileged_activity_but_not_other_logs(): void
    {
        $viewer = SuperAdmin::factory()->superAdmin()->create();
        $otherAdmin = SuperAdmin::factory()->admin()->create();
        $user = User::factory()->create();
        $shop = ShopOwner::factory()->create();

        $this->activity('privileged_administrator_role_changed', $otherAdmin, SuperAdmin::class, $otherAdmin->id);
        $this->activity('shop_reports_moderated', $otherAdmin, ShopOwner::class, $shop->id, [
            'applied_action' => 'warn',
            'outcome' => 'warn',
        ]);
        $this->activity('user_suspended', $otherAdmin, User::class, $user->id, [
            'prior_status' => 'active',
            'new_status' => 'suspended',
        ]);
        Activity::query()->create([
            'log_name' => 'default',
            'description' => 'unrelated application activity',
            'event' => 'created',
        ]);

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->component('superAdmin/Audit/PrivilegedAuditHistory')
                ->has('entries', 3)
                ->where('entries.0.event', 'user_suspended')
                ->where('entries.1.event', 'shop_reports_moderated')
                ->where('entries.2.event', 'privileged_administrator_role_changed')
                ->where('pagination.per_page', 25)
            );
    }

    public function test_admin_sees_own_events_and_capability_scoped_operational_events_only(): void
    {
        $viewer = SuperAdmin::factory()->admin()->create();
        $otherAdmin = SuperAdmin::factory()->admin()->create();
        $shop = ShopOwner::factory()->create();

        $this->activity('privileged_administrator_role_changed', $viewer, SuperAdmin::class, $viewer->id);
        $this->activity('privileged_administrator_role_changed', $otherAdmin, SuperAdmin::class, $otherAdmin->id);
        $this->activity('shop_registration_approved', $otherAdmin, ShopOwner::class, $shop->id);
        $this->activity('shop_reports_moderated', $otherAdmin, ShopOwner::class, $shop->id, [
            'applied_action' => 'dismiss',
            'outcome' => 'dismiss',
        ]);
        $this->activity('premium_plan_updated', $otherAdmin, null, null, [
            'changes' => ['price' => ['from' => 10, 'to' => 20]],
        ]);
        $this->activity('privileged_bootstrap_created', null, SuperAdmin::class, $otherAdmin->id);

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 3)
                ->where('entries.0.event', 'shop_reports_moderated')
                ->where('entries.1.event', 'shop_registration_approved')
                ->where('entries.2.event', 'privileged_administrator_role_changed')
                ->missing('entries.0.metadata.changes')
            );
    }

    public function test_filters_are_allowlisted_scoped_and_pagination_is_capped(): void
    {
        $viewer = SuperAdmin::factory()->superAdmin()->create();
        $otherAdmin = SuperAdmin::factory()->admin()->create();
        $user = User::factory()->create();
        $correlationId = (string) Str::uuid();

        $this->activity('user_suspended', $otherAdmin, User::class, $user->id, [
            'correlation_id' => $correlationId,
        ], '2026-08-10 10:00:00');
        $this->activity('user_reactivated', $otherAdmin, User::class, $user->id, [], '2026-08-11 10:00:00');

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit?event=user_suspended&actor_id='.$otherAdmin->id
                .'&target_type=user&target_id='.$user->id
                .'&correlation_id='.$correlationId
                .'&date_from=2026-08-10&date_to=2026-08-10&per_page=100')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.event', 'user_suspended')
                ->where('pagination.per_page', 100)
                ->where('filters.event', 'user_suspended')
                ->where('filters.actor_id', $otherAdmin->id)
            );

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit?event=not-an-allowlisted-event&per_page=101')
            ->assertSessionHasErrors(['event', 'per_page']);
    }

    public function test_safe_serialization_excludes_raw_properties_and_sensitive_values(): void
    {
        $viewer = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create();

        $this->activity('user_suspended', $viewer, User::class, $user->id, [
            'reason' => 'Policy violation',
            'password' => 'do-not-render',
            'token' => 'do-not-render',
            'raw_request' => ['email' => 'private@example.test'],
            'context' => ['ip_address' => '198.51.100.2'],
        ]);

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.event', 'user_suspended')
                ->where('entries.0.metadata.reason', 'Policy violation')
                ->missing('entries.0.metadata.password')
                ->missing('entries.0.metadata.token')
                ->missing('entries.0.metadata.raw_request')
                ->missing('entries.0.properties')
            );
    }

    public function test_document_renewal_audit_is_visible_with_allowlisted_metadata_only(): void
    {
        $viewer = SuperAdmin::factory()->admin()->create();
        $owner = ShopOwner::factory()->approved()->create();
        $predecessor = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 1,
            'file_path' => 'private/original.png',
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
        ]);
        $predecessor->update([
            'is_current' => null,
            'superseded_at' => now(),
        ]);
        $renewal = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 2,
            'predecessor_document_id' => $predecessor->id,
            'file_path' => 'private/renewal.png',
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
        ]);

        app(\App\Services\PrivilegedAudit::class)->shopDocumentRenewalApproved(
            Request::create('/admin/document-renewals/'.$renewal->id.'/approve', 'POST', [], [], [], [
                'REMOTE_ADDR' => '198.51.100.8',
            ]),
            $viewer,
            $renewal,
            $owner,
            $predecessor,
            [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'version_number' => 2,
                'issued_on' => '2026-08-13',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-08-13',
                'submitted_issued_on' => '2026-08-01',
                'submitted_expiration_mode' => 'dated',
                'submitted_expires_on' => '2027-08-01',
                'path' => 'private/renewal.png',
                'checksum_sha256' => 'secret-checksum',
                'raw_request' => ['file' => 'secret'],
            ],
        );

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.event', 'shop_document_renewal_approved')
                ->where('entries.0.metadata.document_type', 'mayors_permit')
                ->where('entries.0.metadata.logical_slot', 'mayors_permit')
                ->where('entries.0.metadata.version_number', 2)
                ->where('entries.0.metadata.issued_on', '2026-08-13')
                ->where('entries.0.metadata.expiration_mode', 'dated')
                ->where('entries.0.metadata.expires_on', '2027-08-13')
                ->where('entries.0.metadata.submitted_issued_on', '2026-08-01')
                ->where('entries.0.metadata.submitted_expires_on', '2027-08-01')
                ->missing('entries.0.metadata.path')
                ->missing('entries.0.metadata.checksum_sha256')
                ->missing('entries.0.metadata.raw_request')
            );
    }

    public function test_legacy_report_aliases_redirect_to_canonical_audit_history(): void
    {
        $viewer = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/data-reports')
            ->assertRedirect('/admin/audit');

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/superAdmin/data-report-access')
            ->assertRedirect('/admin/audit');

        $auditRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'admin/audit');

        $this->assertCount(1, $auditRoutes);
        $this->assertSame(['GET', 'HEAD'], $auditRoutes->first()->methods());
    }

    public function test_audit_order_and_relation_queries_remain_bounded_for_equal_timestamps(): void
    {
        $viewer = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $timestamp = '2026-08-12 12:00:00';
        $activities = collect([
            $this->activity('user_suspended', $viewer, User::class, $user->id, [], $timestamp),
            $this->activity('user_reactivated', $viewer, User::class, $user->id, [], $timestamp),
            $this->activity('user_archived', $viewer, User::class, $user->id, [], $timestamp),
        ]);

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/audit?per_page=1&page=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.id', $activities->sortByDesc('id')->first()->id));

        $measure = function () use ($viewer): int {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $this->actingAsCompletedPrivileged($viewer)
                ->get('/admin/audit')
                ->assertOk();
            $count = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            return $count;
        };

        $smallCount = $measure();
        foreach (range(1, 30) as $index) {
            $this->activity('user_suspended', $viewer, User::class, $user->id, [], $timestamp);
        }
        $largeCount = $measure();
        self::assertLessThanOrEqual($smallCount + 2, $largeCount);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function activity(
        string $event,
        ?SuperAdmin $actor,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $properties = [],
        string|CarbonImmutable|null $createdAt = null,
    ): Activity {
        $timestamp = $createdAt ?? '2026-08-12 12:00:00';
        $baseProperties = [
            'event' => $event,
            'source' => 'http',
            'correlation_id' => (string) Str::uuid(),
            'actor_type' => $actor instanceof SuperAdmin ? 'super_admin' : null,
            'actor_guard' => $actor instanceof SuperAdmin ? 'super_admin' : null,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'target_type' => $subjectType ? class_basename($subjectType) : null,
            'target_id' => $subjectId,
            'ip_address' => '198.51.100.2',
        ];

        return Activity::query()->create([
            'log_name' => 'privileged',
            'description' => $event,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causer_type' => $actor instanceof SuperAdmin ? SuperAdmin::class : null,
            'causer_id' => $actor?->id,
            'properties' => array_merge($baseProperties, $properties),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
