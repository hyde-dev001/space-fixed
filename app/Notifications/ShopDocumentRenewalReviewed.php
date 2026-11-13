<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ShopDocumentRenewalReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $documentId,
        public readonly int $shopOwnerId,
        public readonly string $businessName,
        public readonly string $logicalSlot,
        public readonly string $decision,
        public readonly ?string $decisionReason = null,
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
        $decisionText = $this->decision === 'approved' ? 'approved' : 'rejected';
        $mail = (new MailMessage)
            ->subject('Shop document renewal '.$decisionText)
            ->greeting('Your shop document renewal was '.$decisionText.'.')
            ->line($this->businessName.' document renewal for '.$this->logicalSlot.' has been reviewed.');

        if ($this->decisionReason !== null && $this->decisionReason !== '') {
            $mail->line('Reviewer note: '.$this->decisionReason);
        }

        return $mail
            ->action('Open shop settings', route('shop-owner.settings'))
            ->line('The document history remains available in your compliance settings.');
    }

    /** @return array<string, int|string|null> */
    public function toArray(?object $notifiable): array
    {
        return [
            'document_id' => $this->documentId,
            'shop_owner_id' => $this->shopOwnerId,
            'business_name' => $this->businessName,
            'logical_slot' => $this->logicalSlot,
            'decision' => $this->decision,
            'decision_reason' => $this->decisionReason,
        ];
    }
}
