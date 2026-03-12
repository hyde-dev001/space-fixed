<?php

namespace App\Listeners;

use App\Events\PurchaseRequestSubmittedToFinance;
use App\Mail\PurchaseRequestSubmittedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyFinanceOfNewPR implements ShouldQueue
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
    public function handle(PurchaseRequestSubmittedToFinance $event): void
    {
        try {
            $purchaseRequest = $event->purchaseRequest;
            
            // Get finance team users (users with finance role or permission)
            $financeUsers = User::whereHas('roles', function ($query) {
                $query->where('name', 'finance');
            })->get();

            // Send email to each finance user
            foreach ($financeUsers as $user) {
                Mail::to($user->email)->send(new PurchaseRequestSubmittedMail($purchaseRequest));
            }

            Log::info('Finance team notified of new PR', [
                'pr_id' => $purchaseRequest->id,
                'pr_number' => $purchaseRequest->pr_number,
                'total_cost' => $purchaseRequest->total_cost,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to notify finance team of PR', [
                'pr_id' => $event->purchaseRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
