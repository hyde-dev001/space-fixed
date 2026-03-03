<?php

namespace App\Listeners;

use App\Events\ReplenishmentRequestAccepted;
use App\Mail\ReplenishmentRequestReviewedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyReplenishmentReviewed implements ShouldQueue
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
    public function handle(ReplenishmentRequestAccepted $event): void
    {
        try {
            $replenishmentRequest = $event->replenishmentRequest;
            $requester = $replenishmentRequest->requester;

            if ($requester && $requester->email) {
                Mail::to($requester->email)->send(
                    new ReplenishmentRequestReviewedMail($replenishmentRequest)
                );

                Log::info('Requester notified of replenishment request review', [
                    'request_id' => $replenishmentRequest->id,
                    'request_number' => $replenishmentRequest->request_number,
                    'requester_id' => $requester->id,
                    'status' => $replenishmentRequest->status,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify requester of replenishment review', [
                'request_id' => $event->replenishmentRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
