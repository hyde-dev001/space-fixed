<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class AccountLifecycleWorkflowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_user_suspension_has_one_stable_suspension_appeal_and_audit_across_retries(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $first = $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'First verified reason',
        ]);
        DB::commit();
        $second = $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'First verified reason',
        ]);

        $first->assertOk();
        $second->assertOk();

        $user->refresh();
        $this->assertSame('suspended', $user->getRawOriginal('status'));
        $this->assertNotNull($user->current_suspension_id);
        $this->assertSame(1, AccountSuspension::query()->count());
        $this->assertSame(1, SuspensionAppeal::query()->count());
        $this->assertDatabaseHas('suspension_appeals', [
            'suspension_id' => $user->current_suspension_id,
            'account_type' => AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            'account_id' => $user->id,
            'status' => 'eligible',
        ]);
        $this->assertSame(1, Activity::query()->where('event', 'user_suspended')->count());
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($user): bool {
            return $job->deliveryType === PrivilegedDeliveryType::CUSTOMER_SUSPENSION_NOTICE
                && $job->recipientType === 'user'
                && $job->recipientId === $user->id
                && $job->businessEventId === 'account-suspension:'.$user->current_suspension_id.':notice';
        });
    }

    public function test_conflicting_user_suspension_returns_conflict_without_new_state(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Original reason',
        ])->assertOk();

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Different reason',
        ])->assertStatus(409);

        $this->assertSame(1, AccountSuspension::query()->count());
        $this->assertSame(1, SuspensionAppeal::query()->count());
        $this->assertSame(1, Activity::query()->where('event', 'user_suspended')->count());
        $this->assertSame('Original reason', AccountSuspension::query()->sole()->reason);
    }

    public function test_shop_suspension_requires_approved_source_and_reactivation_does_not_approve_pending_or_rejected_shops(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $approved = $this->approvedPhaseTwoShop();
        $pending = ShopOwner::factory()->pending()->create();
        $rejected = ShopOwner::factory()->rejected()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/shops/{$approved->id}/suspend", [
            'suspension_reason' => 'Shop policy violation',
        ])->assertOk();

        $this->postJson("/admin/shops/{$approved->id}/activate", [
            'reactivation_reason' => 'Shop policy remediation verified',
        ])->assertOk();
        $this->assertSame('approved', $approved->fresh()->getRawOriginal('status'));

        foreach ([$pending, $rejected] as $shop) {
            $this->postJson("/admin/shops/{$shop->id}/activate", [
                'reactivation_reason' => 'Source-state verification',
            ])
                ->assertStatus(409);
            $this->assertSame(
                $shop->getRawOriginal('status'),
                $shop->fresh()->getRawOriginal('status'),
            );
        }
    }

    public function test_linked_active_employee_is_suspended_with_exact_provenance_and_restored_on_reactivation(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'linked-user@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $employee = $this->linkedPhaseTwoEmployee($user, $shop);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Linked employee test',
        ])->assertOk();

        $suspension = AccountSuspension::query()->sole();
        $employee->refresh();
        $this->assertSame($employee->id, $suspension->linked_employee_id);
        $this->assertSame('active', $suspension->linked_employee_prior_status);
        $this->assertSame('suspended', $employee->getRawOriginal('status'));
        $this->assertSame($suspension->id, $employee->privileged_suspension_id);

        $this->postJson("/admin/users/{$user->id}/activate", [
            'reactivation_reason' => 'Verified remediation',
        ])->assertOk();

        $employee->refresh();
        $suspension->refresh();
        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertSame('active', $employee->getRawOriginal('status'));
        $this->assertNull($employee->privileged_suspension_id);
        $this->assertNotNull($suspension->ended_at);
        $this->assertNull($user->fresh()->current_suspension_id);
        $this->assertSame('superseded', SuspensionAppeal::query()->sole()->status);
    }

    public function test_ambiguous_linked_employees_roll_back_the_entire_user_suspension(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'ambiguous-user@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $this->ambiguousPhaseTwoEmployees($user, $shop);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Ambiguous linkage test',
        ])->assertStatus(409);

        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertNull($user->fresh()->current_suspension_id);
        $this->assertSame(0, AccountSuspension::query()->count());
        $this->assertSame(0, SuspensionAppeal::query()->count());
        $this->assertSame(0, Activity::query()->where('event', 'user_suspended')->count());
    }

    public function test_missing_or_superseded_employee_provenance_blocks_reactivation(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'missing-employee@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $employee = $this->linkedPhaseTwoEmployee($user, $shop);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Provenance test',
        ])->assertOk();

        $employee->delete();

        $this->postJson("/admin/users/{$user->id}/activate", [
            'reactivation_reason' => 'Attempted restore',
        ])->assertStatus(409);

        $this->assertSame('suspended', $user->fresh()->getRawOriginal('status'));
        $this->assertNotNull($user->fresh()->current_suspension_id);
        $this->assertNull(AccountSuspension::query()->sole()->ended_at);
    }

    public function test_inactive_or_terminated_linked_employees_are_not_rewritten_or_claimed(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'inactive-employee@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $employee = $this->linkedPhaseTwoEmployee($user, $shop, ['status' => 'inactive']);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Inactive linkage test',
        ])->assertOk();

        $this->assertSame('inactive', $employee->fresh()->getRawOriginal('status'));
        $this->assertNull($employee->fresh()->privileged_suspension_id);
        $this->assertNull(AccountSuspension::query()->sole()->linked_employee_id);
    }

    public function test_already_suspended_unattributed_employee_blocks_user_suspension(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'already-suspended@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $employee = $this->linkedPhaseTwoEmployee($user, $shop, ['status' => 'suspended']);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Existing employee suspension test',
        ])->assertStatus(409);

        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertSame(0, AccountSuspension::query()->count());
        $this->assertNull($employee->fresh()->privileged_suspension_id);
    }

    public function test_mandatory_audit_failure_rolls_back_the_account_suspension(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('userSuspended')->once()->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(PrivilegedAudit::class, $audit);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Audit rollback test',
        ])->assertStatus(500);

        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
        $this->assertSame(0, AccountSuspension::query()->count());
        $this->assertSame(0, SuspensionAppeal::query()->count());
    }

    public function test_archive_and_restore_preserve_relations_and_business_status(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $address = $user->addresses()->create([
            'name' => 'Archive Test',
            'phone' => '555-0100',
            'region' => 'Region',
            'province' => 'Province',
            'city' => 'City',
            'barangay' => 'Barangay',
            'postal_code' => '1000',
            'address_line' => '1 Main Street',
            'is_default' => true,
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [
            'archive_reason' => 'Retention request',
        ])->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('user_addresses', ['id' => $address->id, 'user_id' => $user->id]);

        $this->postJson("/admin/users/{$user->id}/restore", [
            'restore_reason' => 'Retention request reversed',
        ])->assertOk();

        $restored = User::query()->findOrFail($user->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('active', $restored->getRawOriginal('status'));
        $this->assertDatabaseHas('user_addresses', ['id' => $address->id, 'user_id' => $user->id]);
    }

    public function test_shop_archive_and_restore_preserve_approved_status(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $this->actingAsCompletedPrivileged($admin);
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/shops/{$shop->id}/archive", [
            'archive_reason' => 'Shop retention request',
        ])->assertOk();

        $this->assertSoftDeleted('shop_owners', ['id' => $shop->id]);

        $this->postJson("/admin/shops/{$shop->id}/restore", [
            'restore_reason' => 'Shop retention request reversed',
        ])->assertOk();

        $restored = ShopOwner::query()->findOrFail($shop->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('approved', $restored->getRawOriginal('status'));
    }

    public function test_privileged_management_reads_include_archived_accounts_without_changing_normal_scopes(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $shop = $this->approvedPhaseTwoShop();
        $this->actingAsCompletedPrivileged($admin);
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [
            'archive_reason' => 'Archive user for read-model coverage.',
        ])->assertOk();
        $this->postJson("/admin/shops/{$shop->id}/archive", [
            'archive_reason' => 'Archive shop for read-model coverage.',
        ])->assertOk();

        $this->assertNull(User::query()->find($user->id));
        $this->assertNull(ShopOwner::query()->find($shop->id));

        $this->get(route('admin.user-management', ['lifecycle' => 'archived']))
            ->assertInertia(fn ($page) => $page
                ->where('users.0.id', $user->id)
                ->where('users.0.status', 'archived')
                ->where('users.0.accountStatus', 'active')
                ->where('users.0.archived', true));

        $this->get(route('superAdmin.super-admin-user-management', ['lifecycle' => 'archived']))
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.id', $user->id)
                ->where('users.data.0.status', 'archived')
                ->where('users.data.0.accountStatus', 'active')
                ->where('users.data.0.archived', true));

        $this->get(route('admin.registered-shops', ['lifecycle' => 'archived']))
            ->assertInertia(fn ($page) => $page
                ->where('shops.0.id', $shop->id)
                ->where('shops.0.status', 'archived')
                ->where('shops.0.accountStatus', 'approved')
                ->where('shops.0.archived', true)
                ->where('stats.archived', 1));

        $this->get(route('admin.shops.details', $shop->id))
            ->assertOk()
            ->assertJsonPath('shop.archived', true)
            ->assertJsonPath('shop.accountStatus', 'approved');
    }

    public function test_archive_and_restore_require_recent_reauthentication_and_reason(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [])
            ->assertStatus(423);

        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [])
            ->assertStatus(422);
    }

    public function test_archived_suspended_user_restores_as_suspended(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Archive state test',
        ])->assertOk();
        $this->markPrivilegedReauthenticated($admin);

        $this->postJson("/admin/users/{$user->id}/archive", [
            'archive_reason' => 'Archive suspended account',
        ])->assertOk();
        $this->postJson("/admin/users/{$user->id}/restore", [
            'restore_reason' => 'Restore without activation',
        ])->assertOk();

        $this->assertSame('suspended', User::query()->findOrFail($user->id)->getRawOriginal('status'));
    }

    public function test_mismatched_employee_provenance_blocks_reactivation(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $shop = $this->approvedPhaseTwoShop();
        $user = $this->activePhaseTwoUser([
            'email' => 'mismatched-employee@example.test',
            'shop_owner_id' => $shop->id,
        ]);
        $employee = $this->linkedPhaseTwoEmployee($user, $shop);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson("/admin/users/{$user->id}/suspend", [
            'suspension_reason' => 'Mismatched provenance test',
        ])->assertOk();

        $suspension = AccountSuspension::query()->sole();
        $otherSuspension = AccountSuspension::query()->create([
            'account_type' => AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            'account_id' => $user->id,
            'source' => AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
            'reason' => 'Unrelated provenance fixture',
            'started_at' => now(),
        ]);
        $employee->forceFill(['privileged_suspension_id' => $otherSuspension->id])->save();

        $this->postJson("/admin/users/{$user->id}/activate", [
            'reactivation_reason' => 'Attempted mismatched restore',
        ])->assertStatus(409);

        $this->assertSame('suspended', $user->fresh()->getRawOriginal('status'));
        $this->assertNull($suspension->fresh()->ended_at);
    }

    public function test_archive_and_restore_routes_require_recent_reauthentication_and_post(): void
    {
        foreach (['admin.users.archive', 'admin.users.restore', 'admin.shops.archive', 'admin.shops.restore'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertSame(['POST'], $route->methods(), "Unexpected method for {$name}");
            $this->assertContains('privileged.capability:intervene_accounts', $route->middleware());
            $this->assertContains('privileged.recent', $route->middleware());
        }
    }

    public function test_lifecycle_mutations_are_not_available_on_get(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $user = $this->activePhaseTwoUser();
        $this->actingAsCompletedPrivileged($admin);

        $this->get("/admin/users/{$user->id}/suspend")->assertStatus(405);
        $this->get("/admin/users/{$user->id}/archive")->assertStatus(405);
        $this->assertSame('active', $user->fresh()->getRawOriginal('status'));
    }

    private function markPrivilegedReauthenticated(\App\Models\SuperAdmin $admin): void
    {
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => $admin->security_version,
        ]);
    }
}
