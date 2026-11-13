<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ShopDocumentRenewalSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $documentId,
        public readonly int $shopOwnerId,
        public readonly string $businessName,
        public readonly string $logicalSlot,
    ) {
        $this->afterCommit = true;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Shop document renewal awaiting review')
            ->greeting('A shop document renewal is ready for review.')
            ->line($this->businessName.' submitted a new '.$this->logicalSlot.' document.')
            ->action('Open document renewals', route('admin.document-renewals.index'))
            ->line('Review the private evidence and decide from the privileged renewal queue.');
    }

    /** @return array<string, int|string> */
    public function toArray(?object $notifiable): array
    {
        return [
            'document_id' => $this->documentId,
            'shop_owner_id' => $this->shopOwnerId,
            'business_name' => $this->businessName,
            'logical_slot' => $this->logicalSlot,
        ];
    }
}
