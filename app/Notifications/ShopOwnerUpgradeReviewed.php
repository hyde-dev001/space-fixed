<?php

namespace App\Notifications;

use App\Models\ShopOwnerUpgradeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ShopOwnerUpgradeReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly int $upgradeRequestId;

    public readonly string $decision;

    public readonly ?string $decisionReason;

    /**
     * @var array<int, string>
     */
    public readonly array $newlyEnabledModuleKeys;

    public readonly bool $dormantEmployeePermissionWarning;

    /**
     * @param  array<int, string>  $newlyEnabledModuleKeys
     */
    public function __construct(ShopOwnerUpgradeRequest|int $upgradeRequest, string $decision, ?string $decisionReason = null, array $newlyEnabledModuleKeys = [], bool $dormantEmployeePermissionWarning = false)
    {
        $this->afterCommit = true;

        $this->upgradeRequestId = $upgradeRequest instanceof ShopOwnerUpgradeRequest
            ? (int) $upgradeRequest->id
            : $upgradeRequest;
        $this->decision = $decision;
        $this->decisionReason = $decisionReason;
        $this->newlyEnabledModuleKeys = array_values(array_map('strval', $newlyEnabledModuleKeys));
        $this->dormantEmployeePermissionWarning = $dormantEmployeePermissionWarning;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $decisionText = match ($this->decision) {
            ShopOwnerUpgradeRequest::STATUS_APPROVED => 'approved',
            ShopOwnerUpgradeRequest::STATUS_REJECTED => 'rejected',
            ShopOwnerUpgradeRequest::STATUS_SUPERSEDED => 'superseded because the account changed before review',
            default => 'updated',
        };

        $message = (new MailMessage)
            ->subject('Business upgrade request '.$decisionText)
            ->greeting('Your business upgrade request was '.$decisionText.'.')
            ->line('Request #'.$this->upgradeRequestId.' has been reviewed.');

        if ($this->decisionReason) {
            $message->line('Reviewer note: '.$this->decisionReason);
        }

        if ($this->newlyEnabledModuleKeys !== []) {
            $message->line('Newly available modules: '.implode(', ', $this->newlyEnabledModuleKeys).'.');
        }

        if ($this->dormantEmployeePermissionWarning) {
            $message->line('Existing employee permissions remain subject to their current role and module access.');
        }

        return $message->action('Open shop settings', route('shop-owner.settings'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?object $notifiable): array
    {
        return [
            'upgrade_request_id' => $this->upgradeRequestId,
            'decision' => $this->decision,
            'decision_reason' => $this->decisionReason,
            'newly_enabled_module_keys' => $this->newlyEnabledModuleKeys,
            'dormant_employee_permission_warning' => $this->dormantEmployeePermissionWarning,
        ];
    }
}
