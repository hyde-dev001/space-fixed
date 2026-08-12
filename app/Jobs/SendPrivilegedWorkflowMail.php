<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PrivilegedDeliveryType;
use App\Exceptions\PrivilegedDeliveryException;
use App\Mail\PrivilegedPasswordResetMail;
use App\Mail\PrivilegedSetupLinkMail;
use App\Mail\ShopReportWarningMail;
use App\Mail\SuspensionAppealDecisionMail;
use App\Mail\SuspensionAppealSubmittedMail;
use App\Mail\SuspensionNoticeMail;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Notifications\ShopOwnerApproved;
use App\Notifications\ShopOwnerRejected;
use App\Notifications\ShopOwnerUpgradeRequested;
use App\Notifications\ShopOwnerUpgradeReviewed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as NotificationContract;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class SendPrivilegedWorkflowMail implements ShouldQueue, ShouldBeEncrypted, ShouldBeUnique
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public int $uniqueFor = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly PrivilegedDeliveryType $deliveryType,
        public readonly string $businessEventId,
        public readonly string $recipientType,
        public readonly int $recipientId,
        public readonly string $channel,
        array $payload = [],
        public readonly ?string $correlationId = null,
        ?string $requiredCapability = null,
    ) {
        if ($this->businessEventId === '') {
            throw new InvalidArgumentException('A privileged delivery business event ID is required.');
        }

        if (! in_array($this->recipientType, ['super_admin', 'shop_owner', 'user'], true)) {
            throw new InvalidArgumentException('The privileged delivery recipient type is not supported.');
        }

        if ($this->channel !== 'mail') {
            throw new InvalidArgumentException('The privileged delivery channel is not supported.');
        }

        if ($this->correlationId !== null && ! Str::isUuid($this->correlationId)) {
            throw new InvalidArgumentException('The privileged delivery correlation ID must be a UUID.');
        }

        $this->payload = $this->sanitizePayload($payload);
        $this->requiredCapability = $requiredCapability ?? $this->defaultCapability();
    }

    /** @var array<string, mixed> */
    public readonly array $payload;

    public readonly ?string $requiredCapability;

    public function uniqueId(): string
    {
        return implode('|', [
            $this->deliveryType->value,
            $this->businessEventId,
            $this->recipientType,
            (string) $this->recipientId,
            $this->channel,
        ]);
    }

    public function handle(): void
    {
        $recipient = $this->resolveRecipient();

        if ($this->requiresPrivilegedRecipient()
            && ! $this->isEligiblePrivilegedRecipient($recipient)) {
            return;
        }

        try {
            $this->send($recipient);
        } catch (PrivilegedDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Privileged workflow delivery failed.', $this->safeContext());

            throw PrivilegedDeliveryException::fromTransport($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Privileged workflow delivery permanently failed.', $this->safeContext());
    }

    /** @return array<string, int|string|null> */
    public function safeContext(): array
    {
        return [
            'delivery_type' => $this->deliveryType->value,
            'business_event_id' => $this->businessEventId,
            'recipient_type' => $this->recipientType,
            'recipient_id' => $this->recipientId,
            'channel' => $this->channel,
            'correlation_id' => $this->correlationId,
        ];
    }

    private function defaultCapability(): ?string
    {
        return match ($this->deliveryType) {
            PrivilegedDeliveryType::SUSPENSION_APPEAL_SUBMITTED => SuperAdmin::CAP_VIEW_APPEALS,
            PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REQUESTED => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            default => null,
        };
    }

    private function requiresPrivilegedRecipient(): bool
    {
        return in_array($this->deliveryType, [
            PrivilegedDeliveryType::SUSPENSION_APPEAL_SUBMITTED,
            PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REQUESTED,
        ], true);
    }

    private function resolveRecipient(): ?Model
    {
        return match ($this->recipientType) {
            'super_admin' => SuperAdmin::query()->find($this->recipientId),
            'shop_owner' => ShopOwner::query()->find($this->recipientId),
            'user' => User::query()->find($this->recipientId),
            default => null,
        };
    }

    private function isEligiblePrivilegedRecipient(?Model $recipient): bool
    {
        return $recipient instanceof SuperAdmin
            && $recipient->isActive()
            && $recipient->hasCompletedMfaSetup()
            && ($this->requiredCapability === null || $recipient->hasCapability($this->requiredCapability));
    }

    private function send(?Model $recipient): void
    {
        match ($this->deliveryType) {
            PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP => Mail::to($this->string('email'))->sendNow(new PrivilegedSetupLinkMail(
                recipientName: $this->string('recipient_name'),
                email: $this->string('email'),
                rawToken: $this->string('raw_token'),
            )),
            PrivilegedDeliveryType::PRIVILEGED_PASSWORD_RESET => Mail::to($this->string('email'))->sendNow(new PrivilegedPasswordResetMail(
                recipientName: $this->string('recipient_name'),
                email: $this->string('email'),
                rawToken: $this->string('raw_token'),
            )),
            PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED => $this->sendNotification(
                $recipient,
                new ShopOwnerApproved($recipient, $this->string('setup_token')),
            ),
            PrivilegedDeliveryType::SHOP_REGISTRATION_REJECTED => $this->sendNotification(
                $recipient,
                new ShopOwnerRejected($recipient, $this->nullableString('rejection_reason')),
            ),
            PrivilegedDeliveryType::SHOP_SUSPENSION_NOTICE,
            PrivilegedDeliveryType::CUSTOMER_SUSPENSION_NOTICE => Mail::to($this->string('recipient_email'))->sendNow(new SuspensionNoticeMail(
                accountName: $this->string('account_name'),
                accountTypeLabel: $this->string('account_type_label'),
                reason: $this->nullableString('reason'),
                appealUrl: $this->string('appeal_url'),
                expiresAtLabel: $this->string('expires_at_label'),
            )),
            PrivilegedDeliveryType::SHOP_REPORT_WARNING => Mail::to($this->string('recipient_email'))->sendNow(new ShopReportWarningMail(
                accountName: $this->string('account_name'),
                reportCount: $this->integer('report_count'),
                primaryReason: $this->string('primary_reason'),
                adminNotes: $this->nullableString('admin_notes'),
                reviewedAtLabel: $this->string('reviewed_at_label'),
            )),
            PrivilegedDeliveryType::SUSPENSION_APPEAL_SUBMITTED => Mail::to($this->string('recipient_email'))->sendNow(new SuspensionAppealSubmittedMail(
                accountName: $this->string('account_name'),
                accountTypeLabel: $this->string('account_type_label'),
                recipientEmail: $this->string('account_recipient_email'),
                suspensionReason: $this->nullableString('suspension_reason'),
                appealMessage: $this->string('appeal_message'),
                submittedAtLabel: $this->string('submitted_at_label'),
                reviewUrl: $this->string('review_url'),
            )),
            PrivilegedDeliveryType::SUSPENSION_APPEAL_DECIDED => Mail::to($this->string('recipient_email'))->sendNow(new SuspensionAppealDecisionMail(
                accountName: $this->string('account_name'),
                accountTypeLabel: $this->string('account_type_label'),
                decision: $this->string('decision'),
                reviewerNotes: $this->nullableString('reviewer_notes'),
            )),
            PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REQUESTED => $this->sendNotification(
                $recipient,
                new ShopOwnerUpgradeRequested(
                    upgradeRequest: $this->integer('upgrade_request_id'),
                    shopOwnerId: $this->integer('shop_owner_id'),
                    businessName: $this->string('business_name'),
                    requestedRegistrationType: $this->string('requested_registration_type'),
                    requestedBusinessType: $this->string('requested_business_type'),
                ),
            ),
            PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED => $this->sendNotification(
                $recipient,
                new ShopOwnerUpgradeReviewed(
                    upgradeRequest: $this->integer('upgrade_request_id'),
                    decision: $this->string('decision'),
                    decisionReason: $this->nullableString('decision_reason'),
                    newlyEnabledModuleKeys: $this->stringArray('newly_enabled_module_keys'),
                    dormantEmployeePermissionWarning: $this->boolean('dormant_employee_permission_warning'),
                ),
            ),
        };
    }

    private function sendNotification(?Model $recipient, NotificationContract $notification): void
    {
        if ($recipient === null) {
            return;
        }

        Notification::sendNow($recipient, $notification);
    }

    /** @param array<string, mixed> $payload */
    private function sanitizePayload(array $payload): array
    {
        $allowed = match ($this->deliveryType) {
            PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP,
            PrivilegedDeliveryType::PRIVILEGED_PASSWORD_RESET => ['recipient_name', 'email', 'raw_token'],
            PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED => ['setup_token'],
            PrivilegedDeliveryType::SHOP_REGISTRATION_REJECTED => ['rejection_reason'],
            PrivilegedDeliveryType::SHOP_SUSPENSION_NOTICE,
            PrivilegedDeliveryType::CUSTOMER_SUSPENSION_NOTICE => ['recipient_email', 'account_name', 'account_type_label', 'reason', 'appeal_url', 'expires_at_label'],
            PrivilegedDeliveryType::SHOP_REPORT_WARNING => ['recipient_email', 'account_name', 'report_count', 'primary_reason', 'admin_notes', 'reviewed_at_label'],
            PrivilegedDeliveryType::SUSPENSION_APPEAL_SUBMITTED => ['recipient_email', 'account_name', 'account_type_label', 'account_recipient_email', 'suspension_reason', 'appeal_message', 'submitted_at_label', 'review_url'],
            PrivilegedDeliveryType::SUSPENSION_APPEAL_DECIDED => ['recipient_email', 'account_name', 'account_type_label', 'decision', 'reviewer_notes'],
            PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REQUESTED => ['upgrade_request_id', 'shop_owner_id', 'business_name', 'requested_registration_type', 'requested_business_type'],
            PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED => ['upgrade_request_id', 'decision', 'decision_reason', 'newly_enabled_module_keys', 'dormant_employee_permission_warning'],
        };

        $safePayload = [];
        foreach ($allowed as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value) || $value === null) {
                $safePayload[$key] = $value;
            } elseif (is_array($value)) {
                $safePayload[$key] = array_values(array_filter($value, static fn (mixed $item): bool => is_scalar($item)));
            }
        }

        return $safePayload;
    }

    private function string(string $key): string
    {
        return (string) ($this->payload[$key] ?? '');
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function integer(string $key): int
    {
        return (int) ($this->payload[$key] ?? 0);
    }

    private function boolean(string $key): bool
    {
        return (bool) ($this->payload[$key] ?? false);
    }

    /** @return array<int, string> */
    private function stringArray(string $key): array
    {
        return array_values(array_map('strval', (array) ($this->payload[$key] ?? [])));
    }
}
