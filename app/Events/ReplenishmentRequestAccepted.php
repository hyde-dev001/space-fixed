<?php

namespace App\Events;

use App\Models\ReplenishmentRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReplenishmentRequestAccepted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $replenishmentRequest;

    /**
     * Create a new event instance.
     */
    public function __construct(ReplenishmentRequest $replenishmentRequest)
    {
        $this->replenishmentRequest = $replenishmentRequest;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('procurement.' . $this->replenishmentRequest->shop_owner_id),
        ];
    }
}
