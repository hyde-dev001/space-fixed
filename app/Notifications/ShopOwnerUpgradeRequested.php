<?php

namespace App\Notifications;

use App\Models\ShopOwnerUpgradeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ShopOwnerUpgradeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly int $upgradeRequestId;

    public readonly int $shopOwnerId;

    public readonly string $businessName;

    public readonly string $requestedRegistrationType;

    public readonly string $requestedBusinessType;

    public function __construct(ShopOwnerUpgradeRequest|int $upgradeRequest, ?int $shopOwnerId = null, ?string $businessName = null, ?string $requestedRegistrationType = null, ?string $requestedBusinessType = null)
    {
        $this->afterCommit = true;

        if ($upgradeRequest instanceof ShopOwnerUpgradeRequest) {
            $this->upgradeRequestId = (int) $upgradeRequest->id;
            $this->shopOwnerId = (int) $upgradeRequest->shop_owner_id;
            $this->businessName = (string) ($upgradeRequest->shopOwner?->business_name ?? 'Shop owner');
            $this->requestedRegistrationType = (string) $upgradeRequest->requested_registration_type;
            $this->requestedBusinessType = (string) $upgradeRequest->requested_business_type;

            return;
        }

        $this->upgradeRequestId = $upgradeRequest;
        $this->shopOwnerId = (int) ($shopOwnerId ?? 0);
        $this->businessName = (string) ($businessName ?? 'Shop owner');
        $this->requestedRegistrationType = (string) ($requestedRegistrationType ?? 'company');
        $this->requestedBusinessType = (string) ($requestedBusinessType ?? 'both');
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
        return (new MailMessage)
            ->subject('Business upgrade request awaiting review')
            ->greeting('A business upgrade request is ready for review.')
            ->line("{$this->businessName} requested a {$this->requestedRegistrationType} registration and {$this->requestedBusinessType} capability.")
            ->action('Open upgrade requests', route('admin.business-upgrade-requests.index', ['status' => 'pending']))
            ->line('Review the private evidence and decide from the SuperAdmin queue.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?object $notifiable): array
    {
        return [
            'upgrade_request_id' => $this->upgradeRequestId,
            'shop_owner_id' => $this->shopOwnerId,
            'business_name' => $this->businessName,
            'requested_registration_type' => $this->requestedRegistrationType,
            'requested_business_type' => $this->requestedBusinessType,
        ];
    }
}
