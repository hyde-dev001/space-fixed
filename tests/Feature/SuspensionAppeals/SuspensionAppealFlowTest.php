<?php

namespace Tests\Feature\SuspensionAppeals;

use App\Mail\SuspensionAppealDecisionMail;
use App\Mail\SuspensionAppealSubmittedMail;
use App\Mail\SuspensionNoticeMail;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SuspensionAppealFlowTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->actingAs($superAdmin, 'super_admin')
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

        $response = $this->actingAs($superAdmin, 'super_admin')
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
            'status' => 'suspended',
            'email' => 'submit-appeal@example.com',
        ]);

        $appeal = SuspensionAppeal::create([
            'account_type' => 'customer',
            'account_id' => $customer->id,
            'account_name' => $customer->name,
            'recipient_email' => $customer->email,
            'suspension_reason' => 'Suspension reason for test.',
            'status' => 'eligible',
            'appeal_token' => hash('sha256', 'test-submit-token-' . uniqid()),
            'expires_at' => now()->addHours(24),
        ]);

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

        Mail::assertSent(SuspensionAppealSubmittedMail::class, function (SuspensionAppealSubmittedMail $mail) use ($superAdmin) {
            return $mail->hasTo($superAdmin->email);
        });
    }

    public function test_super_admin_can_approve_submitted_appeal_and_send_decision_email(): void
    {
        Mail::fake();

        $superAdmin = SuperAdmin::query()->firstOrFail();
        $customer = User::factory()->create([
            'status' => 'suspended',
            'email' => 'decision-appeal@example.com',
        ]);

        $appeal = SuspensionAppeal::create([
            'account_type' => 'customer',
            'account_id' => $customer->id,
            'account_name' => $customer->name,
            'recipient_email' => $customer->email,
            'suspension_reason' => 'Suspension reason for approval test.',
            'status' => 'submitted',
            'appeal_token' => hash('sha256', 'test-decision-token-' . uniqid()),
            'appeal_message' => 'I acknowledge the issue and have taken corrective action to prevent recurrence.',
            'submitted_at' => now()->subHour(),
            'expires_at' => now()->addHours(24),
        ]);

        $response = $this->actingAs($superAdmin, 'super_admin')
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
