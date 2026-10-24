<?php

require __DIR__.'/failed_delivery_fixture.php';

use App\Models\Logistics\{DeliveryAssignment, DeliveryEvent, Shipment, ShipmentCustodyTransfer, ShipmentLeg};
use App\Models\{Notification, Order, ShopOwner, User};
use App\Services\Logistics\{BatchDispatchService, DeliveryEventService, ShipmentCustodyService, ShipmentLegService};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$mode = $argv[1];
if ($mode === 'seed') { echo json_encode(recoveryFixture($argv[2]), JSON_THROW_ON_ERROR); exit; }
$data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$leg = ShipmentLeg::findOrFail($data['leg_id']);
$dispatcher = User::findOrFail($data['actors']['dispatcher']['id']);
$rider = User::findOrFail($data['actors']['a']['id']);
$service = app(ShipmentLegService::class);
if ($mode === 'prepare') {
    $scenario = $argv[3];
    if (in_array($scenario, ['retry', 'handoff', 'receipt', 'return_retry', 'replace_receipt', 'cod_reassign'], true)) $leg->update(['status' => 'needs_resolution']);
    if (in_array($scenario, ['handoff', 'receipt', 'replace_receipt'], true)) {
        $leg->assignments()->update(['status' => 'cancelled']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $data['actors']['b']['rider_id'], 'status' => 'accepted']);
    }
    if (in_array($scenario, ['receipt', 'replace_receipt'], true)) {
        Storage::disk('local')->put('logistics-proof/concurrency.jpg', 'synthetic evidence');
        $transfer = app(ShipmentCustodyService::class)->request($leg, $dispatcher, 'rider', $data['actors']['b']['rider_id'], (string) Str::uuid());
        app(ShipmentCustodyService::class)->release($transfer, $rider, 'logistics-proof/concurrency.jpg');
        $data['transfer_id'] = $transfer->id;
    }
    if (in_array($scenario, ['batch', 'batch_failure'], true)) {
        $schedule = ['status' => 'pending', 'schedule_status' => 'scheduled', 'scheduled_delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'morning'];
        $leg->assignments()->update(['status' => 'cancelled']); $leg->update($schedule);
        $order = Order::factory()->create(['shop_owner_id' => $data['shop_id'], 'delivery_method' => 'shop_owned']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $data['shop_id'], 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery']);
        $data['other_leg_id'] = ShipmentLeg::factory()->create([...$schedule, 'shipment_id' => $shipment->id])->id;
    }
    if ($scenario === 'batch_failure') {
        $batchService = app(BatchDispatchService::class);
        $batch = $batchService->createDraft(ShopOwner::findOrFail($data['shop_id']), now(config('app.shop_timezone'))->toDateString(), 'morning', [$leg->id, $data['other_leg_id']]);
        $profile = \App\Models\Logistics\RiderProfile::findOrFail($data['actors']['a']['rider_id']);
        $batchService->offer($batch, $profile, ShopOwner::findOrFail($data['shop_id']));
        $batchService->accept($batch->fresh(), $profile);
        $data['batch_id'] = $batch->id;
        $data['assignment_id'] = $leg->assignments()->latest('id')->value('id');
    }
    if ($scenario === 'cod_reassign') {
        $order = Order::findOrFail($leg->shipment->source_id);
        $order->update(['payment_method' => 'cod', 'payment_status' => 'pending']);
        $leg->update(['status' => 'in_transit']);
        $cash = app(\App\Services\CodCollectionService::class);
        $data['collection_id'] = $cash->cashCollected($order->fresh(), $rider, ['amount' => $cash->expectedAmount($order->fresh())])->id;
        $leg->update(['status' => 'needs_resolution']);
    }
    if ($scenario === 'notification') {
        $event = DeliveryEvent::create(['shipment_id' => $leg->shipment_id, 'shipment_leg_id' => $leg->id, 'event_type' => 'delivery_attempt_failed', 'visibility' => 'customer', 'notification_requested_at' => now(), 'metadata' => ['delivery_attempt_id' => 123456]]);
        $data['event_id'] = $event->id;
    }
    echo json_encode($data, JSON_THROW_ON_ERROR); exit;
}
if ($mode === 'state') {
    echo json_encode(['status' => $leg->status->value, 'attempts' => $leg->attempts()->count(), 'assignments' => $leg->assignments()->count(),
        'active_assignments' => $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->count(),
        'transfers' => $leg->custodyTransfers()->count(), 'proofs' => $leg->proofs()->count(),
        'custody_rider' => $leg->custody_rider_profile_id, 'batch_id' => $leg->delivery_batch_id, 'resolution' => $leg->resolution_type,
        'return_legs' => $leg->returnLeg()->count(), 'collection_rider' => isset($data['collection_id']) ? \App\Models\CodCollection::findOrFail($data['collection_id'])->rider_user_id : null,
        'remittances' => \App\Models\CodRemittance::where('shop_owner_id', $data['shop_id'])->count(),
        'notifications' => Notification::where('shop_id', $data['shop_id'])->where('type', 'logistics_delivery_failed')->count()], JSON_THROW_ON_ERROR); exit;
}
while (microtime(true) < (float) $argv[4]) usleep(1000);
try {
    $result = match ($argv[3]) {
        'failure' => $service->recordFailedAttempt($leg, ['delivery_assignment_id' => $data['assignment_id'], 'idempotency_key' => $data['request_key'], 'reason_code' => 'other', 'notes' => 'Synthetic concurrent visit']),
        'retry', 'retry_new' => $service->resolveRetry($leg, 'Synthetic concurrent retry', ['request_key' => $data['request_key'], 'expected_assignment_id' => $data['assignment_id'], 'rider_profile_id' => $data['actors'][$argv[3] === 'retry_new' ? 'b' : 'a']['rider_id'], 'delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'morning'], $dispatcher),
        'handoff' => app(ShipmentCustodyService::class)->request($leg, $dispatcher, 'rider', $data['actors']['b']['rider_id'], (string) Str::uuid()),
        'receipt' => app(ShipmentCustodyService::class)->receive(ShipmentCustodyTransfer::findOrFail($data['transfer_id']), User::findOrFail($data['actors']['b']['id']), 'logistics-proof/concurrency.jpg'),
        'batch' => app(BatchDispatchService::class)->createDraft(ShopOwner::findOrFail($data['shop_id']), now(config('app.shop_timezone'))->toDateString(), 'morning', [$leg->id, $data['other_leg_id']]),
        'return' => $service->requireReturn($leg, 'Synthetic concurrent return', $dispatcher),
        'remittance' => app(\App\Services\CodRemittanceService::class)->submit($rider, ['collection_ids' => [$data['collection_id']], 'idempotency_key' => $data['request_key']]),
        'batch_start' => app(BatchDispatchService::class)->start(\App\Models\Logistics\DeliveryBatch::findOrFail($data['batch_id']), \App\Models\Logistics\RiderProfile::findOrFail($data['actors']['a']['rider_id'])),
        'notification' => app(DeliveryEventService::class)->deliverNotifications($data['event_id']),
    };
    echo json_encode(['result' => 'ok', 'id' => is_object($result) ? ($result->id ?? null) : null], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'conflict', 'fields' => array_keys($exception->errors())], JSON_THROW_ON_ERROR);
}
