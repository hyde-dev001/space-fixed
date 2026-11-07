<?php

require __DIR__.'/failed_delivery_environment.php';

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\ShipmentLeg;
use App\Models\OrderRefund;

$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$batch = $fixture['batch_id'] ? DeliveryBatch::findOrFail($fixture['batch_id']) : null;
$legs = ShipmentLeg::with(['attempts', 'custodyTransfers'])->whereIn('id', $fixture['leg_ids'])->get();
$completed = $legs->whereIn('id', $fixture['completed_ids']);
$affected = $legs->whereIn('id', $fixture['affected_ids']);
echo json_encode(['batch_status' => $batch?->status, 'attempts' => $legs->sum(fn ($leg) => $leg->attempts->count()),
    'incidents' => $batch ? DeliveryIncident::where('delivery_batch_id', $batch->id)->count() : 0,
    'completed_preserved' => $completed->every(fn ($leg) => $leg->status->value === 'delivered' && $leg->rider_progress_state->value === 'rider_released'),
    'all_at_shop' => $affected->every(fn ($leg) => $leg->custody_state === 'shop'),
    'pending_transfers' => $affected->sum(fn ($leg) => $leg->custodyTransfers->whereIn('status', ['requested', 'released'])->count()),
    'custody_riders' => $affected->pluck('custody_rider_profile_id')->values()->all(),
    'affected_delivered' => $affected->every(fn ($leg) => $leg->status->value === 'delivered'),
    'refunds_unchanged' => OrderRefund::where('shop_owner_id', $fixture['shop_id'])->count() === $fixture['initial_refunds']], JSON_THROW_ON_ERROR);
