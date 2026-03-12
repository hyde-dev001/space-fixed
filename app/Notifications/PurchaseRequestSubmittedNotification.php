<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $purchaseRequest;

    /**
     * Create a new notification instance.
     */
    public function __construct(PurchaseRequest $purchaseRequest)
    {
        $this->purchaseRequest = $purchaseRequest;
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
        return (new MailMessage)
                    ->subject('New Purchase Request for Finance Approval')
                    ->line('A new purchase request has been submitted for your approval.')
                    ->line('**PR Number:** ' . $this->purchaseRequest->pr_number)
                    ->line('**Product:** ' . $this->purchaseRequest->product_name)
                    ->line('**Quantity:** ' . $this->purchaseRequest->quantity)
                    ->line('**Total Cost:** ₱' . number_format($this->purchaseRequest->total_cost, 2))
                    ->line('**Priority:** ' . ucfirst($this->purchaseRequest->priority))
                    ->action('Review Purchase Request', url('/erp/procurement/purchase-requests/' . $this->purchaseRequest->id))
                    ->line('Please review and approve or reject this request.');
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
            'total_cost' => $this->purchaseRequest->total_cost,
            'priority' => $this->purchaseRequest->priority,
            'message' => 'New purchase request submitted for approval',
        ];
    }
}
