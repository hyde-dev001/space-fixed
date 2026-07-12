<?php

namespace App\Console\Commands;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\DeliveryEventService;
use Illuminate\Console\Command;

class MonitorOverdueDeliveries extends Command
{
    protected $signature = 'logistics:monitor-overdue';
    protected $description = 'Flag overdue shop-owned delivery work without changing delivery state';

    public function handle(DeliveryEventService $events): int
    {
        DeliveryBatch::query()->with('legs.shipment')
            ->where('status', 'offered')->where('offered_at', '<=', now()->subMinutes(30))
            ->each(function ($batch) use ($events) {
                $leg = $batch->legs->first();
                if (!$leg || $leg->events()->where('event_type', 'overdue_batch_offer')->exists()) return;
                $events->record($leg->shipment, $leg, [
                    'event_type' => 'overdue_batch_offer',
                    'message' => 'Rider offer is awaiting a response.',
                    'metadata' => ['delivery_batch_id' => $batch->id],
                ]);
            });

        ShipmentLeg::query()->with('shipment')
            ->whereNotNull('scheduled_delivery_date')->whereDate('scheduled_delivery_date', '<=', today())
            ->whereIn('status', ['pending', 'assigned', 'picked_up', 'in_transit', 'needs_resolution'])
            ->each(function ($leg) use ($events) {
                $type = $leg->status->value === 'needs_resolution' ? 'overdue_unscheduled_resolution' : 'overdue_delivery_stop';
                if (!$leg->events()->where('event_type', $type)->exists()) {
                    $events->record($leg->shipment, $leg, ['event_type' => $type, 'message' => 'Delivery requires dispatcher attention.']);
                }
                if ($leg->scheduled_delivery_date->isPast() && !$leg->events()->where('event_type', 'delivery_estimate_delayed')->exists()) {
                    $leg->update(['scheduled_delivery_date' => now(config('app.shop_timezone', 'Asia/Manila'))->addDay()->toDateString()]);
                    $events->record($leg->shipment, $leg, ['event_type' => 'delivery_estimate_delayed', 'visibility' => 'customer', 'message' => 'The delivery estimate has changed due to a delay.']);
                }
            });

        return self::SUCCESS;
    }
}
