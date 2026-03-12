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
    public function handle(): void
    {
        try {
            // Get all overdue purchase orders
            $overduePOs = PurchaseOrder::where('status', '!=', 'completed')
                ->where('status', '!=', 'cancelled')
                ->whereNotNull('expected_delivery_date')
                ->where('expected_delivery_date', '<', now())
                ->with(['supplier', 'orderer'])
                ->get();

            if ($overduePOs->count() > 0) {
                // Get procurement team users
                $procurementUsers = User::whereHas('roles', function ($query) {
                    $query->whereIn('name', ['procurement', 'admin']);
                })->get();

                // Group overdue POs by shop owner for better email organization
                $groupedPOs = $overduePOs->groupBy('shop_owner_id');

                foreach ($procurementUsers as $user) {
                    foreach ($groupedPOs as $shopOwnerId => $pos) {
                        Mail::to($user->email)->send(new OverduePurchaseOrdersMail($pos));
                    }
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
