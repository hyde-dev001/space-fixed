<?php

namespace App\Listeners;

use App\Mail\OverduePurchaseOrdersMail;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyOverduePOs
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event - This is called manually from scheduled job
     */
    public function handle(?int $shopOwnerId = null): void
    {
        if (!$shopOwnerId) {
            return;
        }

        try {
            // Get all overdue purchase orders
            $overduePOs = PurchaseOrder::where('status', '!=', 'completed')
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', '!=', 'cancelled')
                ->whereNotNull('expected_delivery_date')
                ->where('expected_delivery_date', '<', now())
                ->with(['supplier', 'orderer'])
                ->get();

            if ($overduePOs->count() > 0) {
                // Get procurement team users
                $procurementUsers = User::where('shop_owner_id', $shopOwnerId)
                    ->whereHas('roles', function ($query) {
                        $query->whereIn('name', ['Procurement Manager', 'Admin']);
                    })->get();

                foreach ($procurementUsers as $user) {
                    Mail::to($user->email)->send(new OverduePurchaseOrdersMail($overduePOs));
                }

                Log::info('Overdue PO notifications sent', [
                    'overdue_count' => $overduePOs->count(),
                    'notified_users' => $procurementUsers->count(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send overdue PO notifications', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
