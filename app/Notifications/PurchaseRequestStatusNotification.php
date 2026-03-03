<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseRequestStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $purchaseRequest;
    protected $status;

    /**
     * Create a new notification instance.
     */
    public function __construct(PurchaseRequest $purchaseRequest, string $status)
    {
        $this->purchaseRequest = $purchaseRequest;
        $this->status = $status;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
                    ->line('Your purchase request has been ' . $this->status . '.')
                    ->line('**PR Number:** ' . $this->purchaseRequest->pr_number)
                    ->line('**Product:** ' . $this->purchaseRequest->product_name)
                    ->line('**Total Cost:** ₱' . number_format($this->purchaseRequest->total_cost, 2));

        if ($this->status === 'approved') {
            $message->subject('Purchase Request Approved')
                   ->line('Your purchase request has been approved by the finance team.')
                   ->line('A purchase order will be created shortly.')
                   ->action('View Purchase Request', url('/erp/procurement/purchase-requests/' . $this->purchaseRequest->id));
        } else {
            $message->subject('Purchase Request Rejected')
                   ->line('Unfortunately, your purchase request has been rejected.')
                   ->line('**Reason:** ' . ($this->purchaseRequest->rejection_reason ?? 'Not specified'))
                   ->action('View Purchase Request', url('/erp/procurement/purchase-requests/' . $this->purchaseRequest->id));
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pr_id' => $this->purchaseRequest->id,
            'pr_number' => $this->purchaseRequest->pr_number,
            'product_name' => $this->purchaseRequest->product_name,
            'status' => $this->status,
            'rejection_reason' => $this->purchaseRequest->rejection_reason,
            'message' => "Purchase request {$this->status}",
        ];
    }
}
