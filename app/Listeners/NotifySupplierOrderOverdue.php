<?php

namespace App\Listeners;

use App\Events\SupplierOrderOverdue;
use App\Models\User;
use App\Notifications\SupplierOrderOverdueNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotifySupplierOrderOverdue implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(SupplierOrderOverdue $event): void
    {
        $supplierOrder = $event->supplierOrder;
        $daysOverdue = $event->daysOverdue;
        
        Log::warning("Supplier order overdue: PO {$supplierOrder->po_number}, {$daysOverdue} days overdue");

        // Get users with supplier order management permissions
        $users = User::permission('inventory.manage_orders')
            ->where('shop_owner_id', $supplierOrder->shop_owner_id)
            ->get();

        if ($users->isEmpty()) {
            Log::warning("No users found with inventory.manage_orders permission for shop_owner_id: {$supplierOrder->shop_owner_id}");
            return;
        }

        // Send notification to all relevant users
        Notification::send($users, new SupplierOrderOverdueNotification($supplierOrder, $daysOverdue));

        Log::info("Overdue supplier order notification sent to " . $users->count() . " users for PO: {$supplierOrder->po_number}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(SupplierOrderOverdue $event, \Throwable $exception): void
    {
        Log::error("Failed to send overdue notification for supplier order ID: {$event->supplierOrder->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
