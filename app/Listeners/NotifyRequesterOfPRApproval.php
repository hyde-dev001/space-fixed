<?php

namespace App\Listeners;

use App\Events\PurchaseRequestApproved;
use App\Mail\PurchaseRequestApprovedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyRequesterOfPRApproval implements ShouldQueue
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
    public function handle(PurchaseRequestApproved $event): void
    {
        try {
            $purchaseRequest = $event->purchaseRequest;
            $requester = $purchaseRequest->requester;

            if ($requester && $requester->email) {
                Mail::to($requester->email)->send(new PurchaseRequestApprovedMail($purchaseRequest));

                Log::info('Requester notified of PR approval', [
                    'pr_id' => $purchaseRequest->id,
                    'pr_number' => $purchaseRequest->pr_number,
                    'requester_id' => $requester->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify requester of PR approval', [
                'pr_id' => $event->purchaseRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
