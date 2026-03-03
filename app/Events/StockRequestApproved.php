<?php

namespace App\Events;

use App\Models\StockRequestApproval;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockRequestApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $stockRequest;

    /**
     * Create a new event instance.
     */
    public function __construct(StockRequestApproval $stockRequest)
    {
        $this->stockRequest = $stockRequest;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('procurement.' . $this->stockRequest->shop_owner_id),
        ];
    }
}
