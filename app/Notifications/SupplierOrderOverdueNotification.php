<?php

namespace App\Notifications;

use App\Models\SupplierOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupplierOrderOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected SupplierOrder $supplierOrder;
    protected int $daysOverdue;

    /**
     * Create a new notification instance.
     */
    public function __construct(SupplierOrder $supplierOrder, int $daysOverdue)
    {
        $this->supplierOrder = $supplierOrder;
        $this->daysOverdue = $daysOverdue;
    }

    /**
     * Get the notification's delivery channels.
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
            ->subject('Overdue Supplier Order: ' . $this->supplierOrder->po_number)
            ->warning()
            ->line('Supplier order **' . $this->supplierOrder->po_number . '** is overdue by **' . $this->daysOverdue . ' days**.')
            ->line('Supplier: **' . $this->supplierOrder->supplier->name . '**')
            ->line('Expected Delivery: **' . $this->supplierOrder->expected_delivery_date . '**')
            ->line('Order Status: **' . ucwords(str_replace('_', ' ', $this->supplierOrder->status)) . '**')
            ->action('View Order', url('/erp/inventory/supplier-orders/' . $this->supplierOrder->id))
            ->line('Please follow up with the supplier to get an updated delivery timeline.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supplier_order_overdue',
            'supplier_order_id' => $this->supplierOrder->id,
            'po_number' => $this->supplierOrder->po_number,
            'supplier_name' => $this->supplierOrder->supplier->name,
            'days_overdue' => $this->daysOverdue,
            'expected_delivery_date' => $this->supplierOrder->expected_delivery_date,
            'status' => $this->supplierOrder->status,
            'message' => "Supplier order {$this->supplierOrder->po_number} is overdue by {$this->daysOverdue} days. Expected delivery: {$this->supplierOrder->expected_delivery_date}",
        ];
    }
}
