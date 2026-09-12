<?php

namespace Tests\Feature\SuspensionAppeals;

use App\Enums\NotificationType;
use App\Mail\SuspensionAppealDecisionMail;
use App\Mail\SuspensionAppealSubmittedMail;
use App\Mail\SuspensionNoticeMail;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class SuspensionAppealFlowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SuperAdmin::factory()->superAdmin()->create();
    }

    private function postWithCsrf(string $uri, array $payload = [], array $headers = [])
    {
        $token = 'test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->post($uri, array_merge($payload, ['_token' => $token]), $headers);
    }

    public function test_super_admin_suspending_customer_creates_appeal_and_sends_notice_email(): void
    {
        Mail::fake();

        $superAdmin = SuperAdmin::query()->firstOrFail();
        $customer = User::factory()->create([
            'status' => 'active',
            'email' => 'appeal-customer@example.com',
        ]);

        $response = $this->actingAsCompletedPrivileged($superAdmin)
            ->postWithCsrf("/admin/users/{$customer->id}/suspend", [
                'suspension_reason' => 'Repeated abusive conduct in transactions.',
            ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('suspension_appeals', [
            'account_type' => 'customer',
            'account_id' => $customer->id,
            'recipient_email' => $customer->email,
            'status' => 'eligible',
        ]);

        Mail::assertSent(SuspensionNoticeMail::class, function (SuspensionNoticeMail $mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }

    public function test_super_admin_suspending_shop_creates_appeal_and_sends_notice_email(): void
    {
        Mail::fake();

        $superAdmin = SuperAdmin::query()->firstOrFail();
        $shopOwner = ShopOwner::factory()->approved()->create([
            'email' => 'appeal-shop@example.com',
        ]);

        $response = $this->actingAsCompletedPrivileged($superAdmin)
            ->postWithCsrf("/admin/shops/{$shopOwner->id}/suspend", [
                'suspension_reason' => 'Multiple verified policy violations from customer reports.',
            ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('shop_owners', [
            'id' => $shopOwner->id,
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('suspension_appeals', [
            'account_type' => 'shop_owner',
            'account_id' => $shopOwner->id,
            'recipient_email' => $shopOwner->email,
            'status' => 'eligible',
        ]);

        Mail::assertSent(SuspensionNoticeMail::class, function (SuspensionNoticeMail $mail) use ($shopOwner) {
            return $mail->hasTo($shopOwner->email);
        });
    }

    public function test_public_signed_endpoint_submits_suspension_appeal(): void
    {
        Mail::fake();

        $superAdmin = SuperAdmin::query()->firstOrFail();

        $customer = User::factory()->create([
            'status' => 'active',
            'email' => 'submit-appeal@example.com',
        ]);

        $this->actingAsCompletedPrivileged($superAdmin)
            ->postWithCsrf("/admin/users/{$customer->id}/suspend", [
                'suspension_reason' => 'Suspension reason for test.',
            ])
            ->assertStatus(302);

        $appeal = SuspensionAppeal::query()
            ->where('account_type', 'customer')
            ->where('account_id', $customer->id)
            ->firstOrFail();

        $showUrl = URL::temporarySignedRoute('appeals.show', now()->addHours(2), [
            'token' => $appeal->appeal_token,
        ]);

        $this->get($showUrl)->assertOk();

        $submitUrl = URL::temporarySignedRoute('appeals.submit', now()->addHours(2), [
            'token' => $appeal->appeal_token,
        ]);

        $submitResponse = $this->postWithCsrf($submitUrl, [
            'appeal_message' => 'I understand the policy concerns and respectfully request reconsideration with corrective actions applied.',
        ], [
            'Accept' => 'application/json',
        ]);

        $submitResponse->assertOk()
            ->assertJsonPath('status', 'submitted');

        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $appeal->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('notifications', [
            'super_admin_id' => $superAdmin->id,
            'type' => NotificationType::SUSPENSION_APPEAL_SUBMITTED->value,
            'action_url' => route('admin.suspension-appeals'),
            'is_read' => false,
            'requires_action' => true,
        ]);
        $this->assertSame(1, Notification::query()
            ->where('super_admin_id', $superAdmin->id)
            ->where('type', NotificationType::SUSPENSION_APPEAL_SUBMITTED->value)
            ->count());

        Mail::assertSent(SuspensionAppealSubmittedMail::class, function (SuspensionAppealSubmittedMail $mail) use ($superAdmin) {
            return $mail->hasTo($superAdmin->email);
        });
    }

    public function test_super_admin_can_approve_submitted_appeal_and_send_decision_email(): void
    {
        Mail::fake();

        $superAdmin = SuperAdmin::query()->firstOrFail();
        $customer = User::factory()->create([
            'status' => 'active',
            'email' => 'decision-appeal@example.com',
        ]);

        $this->actingAsCompletedPrivileged($superAdmin)
            ->postWithCsrf("/admin/users/{$customer->id}/suspend", [
                'suspension_reason' => 'Suspension reason for approval test.',
            ])
            ->assertStatus(302);

        $appeal = SuspensionAppeal::query()
            ->where('account_type', 'customer')
            ->where('account_id', $customer->id)
            ->firstOrFail();
        $appeal->forceFill([
            'status' => 'submitted',
            'appeal_message' => 'I acknowledge the issue and have taken corrective action to prevent recurrence.',
            'submitted_at' => now()->subHour(),
        ])->save();

        $response = $this->actingAsCompletedPrivileged($superAdmin)
            ->postWithCsrf("/admin/appeals/{$appeal->id}/approve", [
                'reviewer_notes' => 'Approved after manual review of evidence and account history.',
            ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('suspension_appeals', [
            'id' => $appeal->id,
            'status' => 'approved',
        ]);

        Mail::assertSent(SuspensionAppealDecisionMail::class, function (SuspensionAppealDecisionMail $mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }
}
