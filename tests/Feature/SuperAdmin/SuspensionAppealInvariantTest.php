<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\AccountSuspensionService;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class SuspensionAppealInvariantTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_get_pages_present_expiry_without_mutating_the_persisted_status(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        [, , $appeal] = $this->suspendCustomer($customer, $admin);
        $appeal->forceFill(['expires_at' => now()->subMinute()])->save();
        $showUrl = URL::temporarySignedRoute('appeals.show', now()->addHour(), ['token' => $appeal->appeal_token]);

        $showResponse = $this->get($showUrl);
        $showResponse->assertOk();
        $showPayload = $this->extractInertiaPageData($showResponse->getContent());

        $this->assertSame('expired', $showPayload['props']['appeal']['status'] ?? null);
        $this->assertSame('eligible', $appeal->fresh()->status);

        $this->actingAsCompletedPrivileged($admin);
        $indexResponse = $this->get('/admin/appeals');
        $indexResponse->assertOk();
        $indexPayload = $this->extractInertiaPageData($indexResponse->getContent());
        $listed = collect($indexPayload['props']['appeals']['data'] ?? [])->firstWhere('id', $appeal->id);

        $this->assertSame('expired', $listed['status'] ?? null);
        $this->assertSame('eligible', $appeal->fresh()->status);
    }

    public function test_submission_requires_the_current_suspension_and_same_retry_is_idempotent(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        [, $suspension, $appeal] = $this->suspendCustomer($customer, $admin);
        $submitUrl = URL::temporarySignedRoute('appeals.submit', now()->addHour(), ['token' => $appeal->appeal_token]);
        $message = 'I understand the policy concerns and have completed the requested corrective actions.';

        $first = $this->postWithCsrf($submitUrl, ['appeal_message' => $message], ['Accept' => 'application/json']);
        $first->assertOk()->assertJson(['status' => 'submitted', 'changed' => true]);
        DB::commit();

        $second = $this->postWithCsrf($submitUrl, ['appeal_message' => $message], ['Accept' => 'application/json']);
        $second->assertOk()->assertJson(['status' => 'submitted', 'changed' => false]);

        $this->postWithCsrf($submitUrl, [
            'appeal_message' => 'This conflicting appeal message attempts to replace the committed submission.',
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame($suspension->id, (int) $appeal->fresh()->suspension_id);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($admin, $appeal): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SUSPENSION_APPEAL_SUBMITTED
                && $job->recipientType === 'super_admin'
                && $job->recipientId === $admin->id
                && $job->businessEventId === 'suspension-appeal-submitted:'.$appeal->id;
        });
    }

    public function test_expired_stale_unlinked_and_wrong_account_appeals_cannot_be_submitted(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        [, , $expired] = $this->suspendCustomer($customer, $admin);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $expiredUrl = URL::temporarySignedRoute('appeals.submit', now()->addHour(), ['token' => $expired->appeal_token]);

        $this->postWithCsrf($expiredUrl, [
            'appeal_message' => 'This appeal is too late and must be rejected as expired.',
        ], ['Accept' => 'application/json'])->assertStatus(410);
        $this->assertSame('expired', $expired->fresh()->status);

        $staleCustomer = $this->activePhaseTwoUser();
        [, , $stale] = $this->suspendCustomer($staleCustomer, $admin);
        app(AccountSuspensionService::class)->reactivateLocked(
            User::query()->whereKey($staleCustomer->id)->lockForUpdate()->firstOrFail(),
            $admin,
            'Manual reactivation superseded the appeal.',
        );
        $staleUrl = URL::temporarySignedRoute('appeals.submit', now()->addHour(), ['token' => $stale->appeal_token]);
        $this->postWithCsrf($staleUrl, [
            'appeal_message' => 'This appeal belongs to a closed suspension and cannot mutate it.',
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $unlinkedCustomer = $this->suspendedPhaseTwoUser();
        $unlinked = SuspensionAppeal::create([
            'account_type' => AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            'account_id' => $unlinkedCustomer->id,
            'account_name' => $unlinkedCustomer->name,
            'recipient_email' => $unlinkedCustomer->email,
            'suspension_reason' => 'Legacy appeal without a linked suspension.',
            'status' => 'eligible',
            'appeal_token' => hash('sha256', 'unlinked-' . uniqid()),
            'expires_at' => now()->addHour(),
        ]);
        $unlinkedUrl = URL::temporarySignedRoute('appeals.submit', now()->addHour(), ['token' => $unlinked->appeal_token]);
        $this->postWithCsrf($unlinkedUrl, [
            'appeal_message' => 'This legacy appeal has no suspension identity and cannot be submitted.',
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame('eligible', $unlinked->fresh()->status);

        $otherCustomer = $this->activePhaseTwoUser();
        [, $otherSuspension, $otherAppeal] = $this->suspendCustomer($otherCustomer, $admin);
        $otherAppeal->forceFill(['account_id' => $customer->id])->save();
        $wrongUrl = URL::temporarySignedRoute('appeals.submit', now()->addHour(), ['token' => $otherAppeal->appeal_token]);
        $this->postWithCsrf($wrongUrl, [
            'appeal_message' => 'This appeal points at a different account than its suspension identity.',
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame('eligible', $otherAppeal->fresh()->status);
        $this->assertSame('suspended', $otherCustomer->fresh()->getRawOriginal('status'));
        $this->assertSame($otherSuspension->id, (int) $otherCustomer->fresh()->current_suspension_id);
    }

    public function test_only_super_admin_can_approve_and_approval_restores_exact_account_and_employee(): void
    {
        Queue::fake();
        $admin = $this->phaseTwoAdmin();
        $superAdmin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser(['email' => 'appeal-employee@example.test']);
        $shop = ShopOwner::factory()->approved()->create();
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $customer->email,
        ]);
        [, $suspension, $appeal] = $this->suspendCustomer($customer, $superAdmin);
        $appeal->forceFill([
            'status' => 'submitted',
            'appeal_message' => 'I understand the policy concerns and have completed corrective actions.',
            'submitted_at' => now()->subHour(),
        ])->save();

        $this->actingAsCompletedPrivileged($admin);
        $this->postJson("/admin/appeals/{$appeal->id}/approve", [
            'reviewer_notes' => 'Admin cannot resolve appeals.',
        ])->assertForbidden();

        $this->actingAsCompletedPrivileged($superAdmin);
        $this->postJson("/admin/appeals/{$appeal->id}/approve", [
            'reviewer_notes' => 'Approved after reviewing the corrective actions.',
        ])->assertOk()->assertJson(['changed' => true, 'status' => 'approved']);
        DB::commit();

        $customer = $customer->fresh();
        $employee = $employee->fresh();
        $this->assertSame('active', $customer->getRawOriginal('status'));
        $this->assertNull($customer->current_suspension_id);
        $this->assertNotNull($suspension->fresh()->ended_at);
        $this->assertSame('active', $employee->getRawOriginal('status'));
        $this->assertNull($employee->privileged_suspension_id);
        $this->assertSame('approved', $appeal->fresh()->status);
        $this->assertSame($superAdmin->id, (int) $appeal->fresh()->reviewer_id);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($customer, $appeal): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SUSPENSION_APPEAL_DECIDED
                && $job->recipientType === 'user'
                && $job->recipientId === $customer->id
                && $job->businessEventId === 'suspension-appeal-decided:'.$appeal->id;
        });
    }

    public function test_rejection_keeps_current_suspension_and_decision_retry_is_idempotent(): void
    {
        Queue::fake();
        $superAdmin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        [, $suspension, $appeal] = $this->suspendCustomer($customer, $superAdmin);
        $appeal->forceFill([
            'status' => 'submitted',
            'appeal_message' => 'I understand the policy concerns and have completed corrective actions.',
            'submitted_at' => now()->subHour(),
        ])->save();
        $this->actingAsCompletedPrivileged($superAdmin);

        $payload = ['reviewer_notes' => 'The evidence does not support restoring this account.'];
        $this->postJson("/admin/appeals/{$appeal->id}/reject", $payload)
            ->assertOk()
            ->assertJson(['changed' => true, 'status' => 'rejected']);
        DB::commit();
        $this->postJson("/admin/appeals/{$appeal->id}/reject", $payload)
            ->assertOk()
            ->assertJson(['changed' => false, 'status' => 'rejected']);
        $this->postJson("/admin/appeals/{$appeal->id}/approve", $payload)->assertStatus(409);

        $customer = $customer->fresh();
        $this->assertSame('suspended', $customer->getRawOriginal('status'));
        $this->assertSame($suspension->id, (int) $customer->current_suspension_id);
        $this->assertNull($suspension->fresh()->ended_at);
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($customer, $appeal): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SUSPENSION_APPEAL_DECIDED
                && $job->recipientType === 'user'
                && $job->recipientId === $customer->id
                && $job->businessEventId === 'suspension-appeal-decided:'.$appeal->id;
        });
    }

    public function test_manual_reactivation_wins_before_appeal_approval_and_leaves_one_terminal_result(): void
    {
        Queue::fake();
        $superAdmin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        [, $suspension, $appeal] = $this->suspendCustomer($customer, $superAdmin);
        $appeal->forceFill([
            'status' => 'submitted',
            'appeal_message' => 'I understand the policy concerns and have completed corrective actions.',
            'submitted_at' => now()->subHour(),
        ])->save();

        DB::transaction(function () use ($customer, $superAdmin): void {
            $locked = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            app(AccountSuspensionService::class)->reactivateLocked(
                $locked,
                $superAdmin,
                'Manual reactivation completed before appeal review.',
            );
        });

        $this->actingAsCompletedPrivileged($superAdmin);
        $this->postJson("/admin/appeals/{$appeal->id}/approve", [
            'reviewer_notes' => 'This approval must lose to the committed manual reactivation.',
        ])->assertStatus(409);

        $this->assertSame('superseded', $appeal->fresh()->status);
        $this->assertSame('active', $customer->fresh()->getRawOriginal('status'));
        $this->assertNull($customer->fresh()->current_suspension_id);
        $this->assertNotNull($suspension->fresh()->ended_at);
        Queue::assertNothingPushed();
    }

    public function test_audit_failure_rolls_back_appeal_decision_and_account_restore(): void
    {
        Queue::fake();
        $superAdmin = $this->phaseTwoSuperAdmin();
        $customer = $this->activePhaseTwoUser();
        [, $suspension, $appeal] = $this->suspendCustomer($customer, $superAdmin);
        $appeal->forceFill([
            'status' => 'submitted',
            'appeal_message' => 'I understand the policy concerns and have completed corrective actions.',
            'submitted_at' => now()->subHour(),
        ])->save();

        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('suspensionAppealDecided')->once()->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(PrivilegedAudit::class, $audit);
        $this->actingAsCompletedPrivileged($superAdmin);

        $this->postJson("/admin/appeals/{$appeal->id}/approve", [
            'reviewer_notes' => 'Rollback this approval.',
        ])->assertStatus(500);

        $this->assertSame('submitted', $appeal->fresh()->status);
        $this->assertSame('suspended', $customer->fresh()->getRawOriginal('status'));
        $this->assertSame($suspension->id, (int) $customer->fresh()->current_suspension_id);
        $this->assertNull($suspension->fresh()->ended_at);
        Queue::assertNothingPushed();
    }

    public function test_expiry_command_is_bounded_and_idempotent(): void
    {
        $admin = $this->phaseTwoSuperAdmin();
        $firstCustomer = $this->activePhaseTwoUser();
        $secondCustomer = $this->activePhaseTwoUser();
        [, , $first] = $this->suspendCustomer($firstCustomer, $admin);
        [, , $second] = $this->suspendCustomer($secondCustomer, $admin);
        $first->forceFill(['expires_at' => now()->subMinutes(2)])->save();
        $second->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('suspension-appeals:expire', ['--limit' => 1])
            ->assertExitCode(0);
        $this->assertSame(1, SuspensionAppeal::query()->where('status', 'expired')->count());

        $this->artisan('suspension-appeals:expire', ['--limit' => 100])
            ->assertExitCode(0);
        $this->assertSame(2, SuspensionAppeal::query()->where('status', 'expired')->count());

        $this->artisan('suspension-appeals:expire', ['--limit' => 100])
            ->assertExitCode(0);
        $this->assertSame(2, SuspensionAppeal::query()->where('status', 'expired')->count());
    }

    /** @return array{0: User, 1: AccountSuspension, 2: SuspensionAppeal} */
    private function suspendCustomer(User $customer, SuperAdmin $actor): array
    {
        return DB::transaction(function () use ($customer, $actor): array {
            $locked = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $result = app(AccountSuspensionService::class)->suspendLocked(
                $locked,
                $actor,
                'Phase two appeal fixture suspension.',
            );

            return [$locked->fresh(), $result['suspension'], $result['appeal']];
        });
    }

    private function postWithCsrf(string $uri, array $payload = [], array $headers = [])
    {
        $token = 'test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->post($uri, array_merge($payload, ['_token' => $token]), $headers);
    }

    private function extractInertiaPageData(string $html): array
    {
        preg_match('/data-page="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Unable to locate Inertia data-page payload.');

        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        $this->assertIsArray($page);

        return $page;
    }
}
