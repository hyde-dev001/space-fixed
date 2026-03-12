<?php

namespace App\Listeners;

use App\Events\PurchaseRequestRejected;
use App\Mail\PurchaseRequestRejectedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyRequesterOfPRRejection implements ShouldQueue
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
    public function handle(PurchaseRequestRejected $event): void
    {
        try {
            $purchaseRequest = $event->purchaseRequest;
            $requester = $purchaseRequest->requester;

            if ($requester && $requester->email) {
                Mail::to($requester->email)->send(new PurchaseRequestRejectedMail($purchaseRequest));

                Log::info('Requester notified of PR rejection', [
                    'pr_id' => $purchaseRequest->id,
                    'pr_number' => $purchaseRequest->pr_number,
                    'requester_id' => $requester->id,
                    'rejection_reason' => $purchaseRequest->rejection_reason,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify requester of PR rejection', [
                'pr_id' => $event->purchaseRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
