<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $purchaseOrder;
    protected $status;

    /**
     * Create a new notification instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder, string $status)
    {
        $this->purchaseOrder = $purchaseOrder;
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
        $statusLabel = ucfirst(str_replace('_', ' ', $this->status));

        $message = (new MailMessage)
                    ->subject("Purchase Order Status: {$statusLabel}")
                    ->line("Purchase order status has been updated to: **{$statusLabel}**")
                    ->line('**PO Number:** ' . $this->purchaseOrder->po_number)
                    ->line('**Product:** ' . $this->purchaseOrder->product_name)
                    ->line('**Supplier:** ' . ($this->purchaseOrder->supplier->name ?? 'N/A'))
                    ->line('**Total Cost:** ₱' . number_format($this->purchaseOrder->total_cost, 2));

        if ($this->status === 'delivered') {
            $message->line('**Delivery Date:** ' . $this->purchaseOrder->actual_delivery_date);
        }

        if ($this->status === 'cancelled') {
            $message->line('**Cancellation Reason:** ' . ($this->purchaseOrder->cancellation_reason ?? 'Not specified'));
        }

        return $message->action('View Purchase Order', url('/erp/procurement/purchase-orders/' . $this->purchaseOrder->id));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'po_id' => $this->purchaseOrder->id,
            'po_number' => $this->purchaseOrder->po_number,
            'product_name' => $this->purchaseOrder->product_name,
            'status' => $this->status,
            'message' => "Purchase order status updated to {$this->status}",
        ];
    }
}
