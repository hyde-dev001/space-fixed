<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Exceptions\PrivilegedDeliveryException;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Mail\SuspensionNoticeMail;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Notifications\ShopOwnerApproved;
use App\Notifications\ShopOwnerUpgradeRequested;
use App\Services\PrivilegedMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PrivilegedDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_runs_after_commit_and_is_discarded_after_rollback(): void
    {
        Queue::fake();

        try {
            DB::transaction(function (): void {
                app(PrivilegedMailDispatcher::class)->dispatch(
                    type: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
                    businessEventId: 'moderation-rollback-1',
                    recipientType: 'shop_owner',
                    recipientId: 101,
                    channel: 'mail',
                    payload: [
                        'recipient_email' => 'owner@example.test',
                        'account_name' => 'Rollback Owner',
                        'report_count' => 1,
                        'primary_reason' => 'policy_violation',
                        'admin_notes' => 'must not dispatch',
                        'reviewed_at_label' => 'Aug 12, 2026',
                    ],
                );

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        Queue::assertNothingPushed();

        DB::beginTransaction();
        app(PrivilegedMailDispatcher::class)->dispatch(
            type: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
            businessEventId: 'moderation-commit-1',
            recipientType: 'shop_owner',
            recipientId: 102,
            channel: 'mail',
            payload: [
                'recipient_email' => 'owner@example.test',
                'account_name' => 'Committed Owner',
                'report_count' => 1,
                'primary_reason' => 'policy_violation',
                'reviewed_at_label' => 'Aug 12, 2026',
            ],
        );
        DB::commit();
        DB::commit();

        Queue::assertPushed(SendPrivilegedWorkflowMail::class, 1);
    }

    public function test_job_is_encrypted_unique_and_has_bounded_retry_settings(): void
    {
        $job = new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
            businessEventId: 'moderation-1',
            recipientType: 'shop_owner',
            recipientId: 10,
            channel: 'mail',
            payload: ['recipient_email' => 'owner@example.test'],
            correlationId: (string) Str::uuid(),
        );

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertSame($job->uniqueId(), (clone $job)->uniqueId());
        $this->assertNotSame(
            $job->uniqueId(),
            (new SendPrivilegedWorkflowMail(
                deliveryType: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
                businessEventId: 'moderation-1',
                recipientType: 'shop_owner',
                recipientId: 11,
                channel: 'mail',
            ))->uniqueId(),
        );
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            $job->timeout,
        );
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 90], $job->backoff);
    }

    public function test_enqueue_failure_after_commit_is_contained_and_sanitized(): void
    {
        Log::spy();
        $dispatcher = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue payload leaked secret token'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);

        DB::beginTransaction();
        app(PrivilegedMailDispatcher::class)->dispatch(
            type: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
            businessEventId: 'moderation-enqueue-failure',
            recipientType: 'shop_owner',
            recipientId: 77,
            channel: 'mail',
            payload: [
                'recipient_email' => 'owner@example.test',
                'admin_notes' => 'secret review note',
            ],
            correlationId: (string) Str::uuid(),
        );
        DB::commit();
        DB::commit();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'Privileged workflow delivery enqueue failed after commit.'
                    && str_contains($serialized, 'moderation-enqueue-failure')
                    && ! str_contains($serialized, 'secret review note')
                    && ! str_contains($serialized, 'token');
            });
    }

    public function test_target_notice_is_sent_even_when_the_shop_owner_is_rejected(): void
    {
        Mail::fake();
        $owner = ShopOwner::factory()->create([
            'status' => 'rejected',
            'email' => 'rejected-owner@example.test',
        ]);

        $job = new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_SUSPENSION_NOTICE,
            businessEventId: 'appeal-1',
            recipientType: 'shop_owner',
            recipientId: $owner->id,
            channel: 'mail',
            payload: [
                'recipient_email' => $owner->email,
                'account_name' => 'Rejected Owner',
                'account_type_label' => 'shop owner',
                'reason' => 'policy violation',
                'appeal_url' => 'https://example.test/appeal',
                'expires_at_label' => 'Aug 19, 2026',
            ],
        );

        $job->handle();

        Mail::assertSent(SuspensionNoticeMail::class, function (SuspensionNoticeMail $mail): bool {
            return $mail->hasTo('rejected-owner@example.test');
        });
    }

    public function test_registration_approval_delivery_sends_the_password_setup_notification_with_the_setup_link(): void
    {
        Notification::fake();
        $owner = ShopOwner::factory()->create([
            'status' => 'approved',
            'email' => 'approved-owner@example.test',
            'password' => null,
        ]);
        $rawToken = 'approval-setup-token';

        (new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED,
            businessEventId: 'shop-registration-approved:'.$owner->id,
            recipientType: 'shop_owner',
            recipientId: $owner->id,
            channel: 'mail',
            payload: ['setup_token' => $rawToken],
        ))->handle();

        Notification::assertSentTo($owner, ShopOwnerApproved::class, function (ShopOwnerApproved $notification, array $channels) use ($owner, $rawToken): bool {
            return $channels === ['mail']
                && $notification->toMail($owner)->actionUrl === route('shop-owner.password.setup', [
                    'token' => $rawToken,
                    'email' => $owner->email,
                ]);
        });
    }

    public function test_transport_failure_is_rethrown_without_the_original_message(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('transport leaked secret token'));

        $job = new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
            businessEventId: 'moderation-transport-failure',
            recipientType: 'shop_owner',
            recipientId: 88,
            channel: 'mail',
            payload: [
                'recipient_email' => 'owner@example.test',
                'account_name' => 'Owner',
                'report_count' => 1,
                'primary_reason' => 'policy_violation',
                'reviewed_at_label' => 'Aug 12, 2026',
            ],
        );

        try {
            $job->handle();
            $this->fail('Expected the transport failure to be sanitized.');
        } catch (PrivilegedDeliveryException $exception) {
            $this->assertSame('Privileged workflow delivery failed.', $exception->getMessage());
            $this->assertStringNotContainsString('secret token', $exception->getMessage());
        }
    }

    public function test_privileged_recipient_is_rechecked_before_fan_out(): void
    {
        Notification::fake();
        $active = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $inactive = SuperAdmin::factory()->admin()->mfaEnrolled()->inactive()->create();

        $payload = [
            'upgrade_request_id' => 44,
            'shop_owner_id' => 55,
            'business_name' => 'Business',
            'requested_registration_type' => 'company',
            'requested_business_type' => 'both',
        ];

        (new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REQUESTED,
            businessEventId: 'upgrade-44',
            recipientType: 'super_admin',
            recipientId: $active->id,
            channel: 'mail',
            payload: $payload,
            requiredCapability: SuperAdmin::CAP_REVIEW_REGISTRATIONS,
        ))->handle();

        (new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REQUESTED,
            businessEventId: 'upgrade-44',
            recipientType: 'super_admin',
            recipientId: $inactive->id,
            channel: 'mail',
            payload: $payload,
            requiredCapability: SuperAdmin::CAP_REVIEW_REGISTRATIONS,
        ))->handle();

        Notification::assertSentTo($active, ShopOwnerUpgradeRequested::class);
        Notification::assertNotSentTo($inactive, ShopOwnerUpgradeRequested::class);
    }

    public function test_failed_delivery_uses_a_sanitized_exception_and_safe_context(): void
    {
        Log::spy();
        $job = new SendPrivilegedWorkflowMail(
            deliveryType: PrivilegedDeliveryType::SHOP_REPORT_WARNING,
            businessEventId: 'moderation-99',
            recipientType: 'shop_owner',
            recipientId: 99,
            channel: 'mail',
            payload: [
                'recipient_email' => 'owner@example.test',
                'admin_notes' => 'secret review note',
            ],
            correlationId: (string) Str::uuid(),
        );

        $exception = PrivilegedDeliveryException::fromTransport(
            new \RuntimeException('transport leaked secret review note and token'),
        );

        $this->assertSame('Privileged workflow delivery failed.', $exception->getMessage());
        $this->assertStringNotContainsString('secret review note', $exception->getMessage());
        $this->assertStringNotContainsString('token', $exception->getMessage());

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'Privileged workflow delivery permanently failed.'
                    && str_contains($serialized, 'moderation-99')
                    && ! str_contains($serialized, 'secret review note')
                    && ! str_contains($serialized, 'token');
            });
    }
}
