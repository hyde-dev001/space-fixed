<?php

require __DIR__.'/failed_delivery_fixture.php';

use App\Models\Logistics\{DeliveryBatch, DeliveryEvent, DeliveryIncident, RiderProfile, ShipmentCustodyTransfer, ShipmentLeg};
use App\Models\{Notification, Order, User};
use App\Services\Logistics\{ArrivalService, BatchDispatchService, BatchInterruptionService, DeliveryEventService, ProofService, ShipmentCustodyService, ShipmentLegService};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$mode = $argv[1];
$service = app(BatchInterruptionService::class);
Storage::disk('local')->put('incident-evidence/batch-race.jpg', 'Synthetic race evidence');
Storage::disk('local')->put('logistics-proof/batch-race.jpg', 'Synthetic parcel evidence');
if ($mode === 'seed') {
    $scenario = $argv[2];
    $data = batchRecoveryFixture('retail_delivery', in_array($scenario, ['start', 'pickup'], true) ? 'before' : 'mid');
    $data['request_key'] = (string) Str::uuid();
    $data['decision_key'] = (string) Str::uuid();
    $leg = ShipmentLeg::findOrFail($data['leg_id']);
    $data['assignment_id'] = $leg->assignments()->latest('id')->value('id');
    $actor = User::findOrFail($data['actors']['a']['id']);
    if ($scenario === 'cod') Order::findOrFail($leg->shipment->source_id)->update(['payment_method' => 'cod', 'payment_status' => 'pending']);
    if ($scenario === 'proof') app(ArrivalService::class)->record($leg, $actor, ['arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect', 'exception_note' => 'Synthetic target has no coordinates.']);
    if (in_array($scenario, ['decision', 'receipt', 'release_decision', 'notification'], true)) {
        $incident = $service->report(DeliveryBatch::findOrFail($data['batch_id']), $actor,
            ['request_key' => $data['request_key'], 'notes' => 'Synthetic batch race', 'photo_paths' => ['incident-evidence/batch-race.jpg']]);
        $data['incident_id'] = $incident->id;
        if (in_array($scenario, ['receipt', 'release_decision'], true)) {
            $incident = $service->resolve($incident, User::findOrFail($data['actors']['dispatcher']['id']), [
                'resolution' => 'reassign', 'note' => 'Synthetic race recovery', 'expected_revision' => 0, 'decision_key' => (string) Str::uuid(),
                'rider_profile_id' => $data['actors']['b']['rider_id'], 'delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'morning', 'handoff_route' => 'direct']);
            $data['recovery_id'] = $incident->recovery_batch_id;
            app(BatchDispatchService::class)->accept($incident->recoveryBatch, RiderProfile::findOrFail($data['actors']['b']['rider_id']));
            foreach ($data['affected_ids'] as $index => $id) {
                $transfer = ShipmentLeg::findOrFail($id)->custodyTransfers()->where('status', 'requested')->sole();
                $data['transfer_id'] = $transfer->id;
                if ($scenario === 'receipt' || $index === 0) app(ShipmentCustodyService::class)->release($transfer, $actor, 'logistics-proof/batch-race.jpg');
                if ($index === 0) app(ShipmentCustodyService::class)->receive($transfer, User::findOrFail($data['actors']['b']['id']), 'logistics-proof/batch-race.jpg');
            }
        }
        $data['event_id'] = DeliveryEvent::where('event_type', 'batch_interruption_reported')->where('metadata->incident_id', $incident->id)->value('id');
    }
    echo json_encode($data, JSON_THROW_ON_ERROR); exit;
}
$data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$batch = DeliveryBatch::findOrFail($data['batch_id']);
$leg = ShipmentLeg::findOrFail($data['leg_id']);
$a = User::findOrFail($data['actors']['a']['id']);
$b = User::findOrFail($data['actors']['b']['id']);
$dispatcher = User::findOrFail($data['actors']['dispatcher']['id']);
$rider = RiderProfile::findOrFail($data['actors']['a']['rider_id']);
if ($mode === 'toggle') {
    app(App\Actions\ShopOwner\ToggleShopOwnerModule::class)->handle(App\Models\ShopOwner::findOrFail($data['shop_id']), 'logistics', $argv[3] === 'on');
    echo json_encode(['module' => $argv[3]], JSON_THROW_ON_ERROR); exit;
}
if ($mode === 'state') {
    $legs = ShipmentLeg::whereIn('id', $data['affected_ids'])->get();
    echo json_encode(['engine' => DB::selectOne('SELECT VERSION() AS v')->v, 'batch_status' => $batch->status,
        'incidents' => DeliveryIncident::where('delivery_batch_id', $batch->id)->count(),
        'revision' => DeliveryIncident::where('delivery_batch_id', $batch->id)->max('revision'),
        'attempts' => $legs->sum(fn ($item) => $item->attempts()->count()),
        'duplicate_active_assignments' => $legs->contains(fn ($item) => $item->assignments()->whereIn('status', ['assigned', 'accepted'])->count() > 1),
        'all_received' => $legs->every(fn ($item) => $item->custody_rider_profile_id == $data['actors']['b']['rider_id']),
        'notifications' => Notification::where('shop_id', $data['shop_id'])->where('title', 'Delivery Route Interrupted')->count()], JSON_THROW_ON_ERROR); exit;
}
while (microtime(true) < (float) $argv[4]) usleep(1000);
$operation = $argv[3];
try {
    $value = match ($operation) {
        'report' => $service->report($batch, $a, ['request_key' => $data['request_key'], 'notes' => 'Synthetic batch race', 'photo_paths' => ['incident-evidence/batch-race.jpg']]),
        'start' => app(BatchDispatchService::class)->start($batch, $rider),
        'pickup' => app(ShipmentLegService::class)->markPickedUp($leg, $rider),
        'transit' => app(ShipmentLegService::class)->markInTransit($leg, $rider),
        'arrival' => app(ArrivalService::class)->record($leg, $a, ['arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect', 'exception_note' => 'Synthetic target has no coordinates.']),
        'proof' => app(ProofService::class)->recordProof($leg, ['handoff_type' => 'delivery', 'proof_type' => 'photo', 'file_path' => 'logistics-proof/batch-race.jpg', 'idempotency_key' => (string) Str::uuid()], $rider),
        'cod' => app(App\Services\CodCollectionService::class)->cashCollected(Order::findOrFail($leg->shipment->source_id), $a, ['amount' => app(App\Services\CodCollectionService::class)->expectedAmount(Order::findOrFail($leg->shipment->source_id))]),
        'failure' => app(ShipmentLegService::class)->recordFailedAttempt($leg, ['delivery_assignment_id' => $data['assignment_id'], 'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'other', 'notes' => 'Genuine synthetic attempted visit']),
        'resume', 'escalate' => $service->resolve(DeliveryIncident::findOrFail($data['incident_id']), $dispatcher, ['resolution' => $operation, 'note' => 'Synthetic concurrent decision', 'decision_key' => (string) Str::uuid(), 'expected_revision' => 0]),
        'replacement_start' => app(BatchDispatchService::class)->start(DeliveryBatch::findOrFail($data['recovery_id']), RiderProfile::findOrFail($data['actors']['b']['rider_id'])),
        'receipt' => app(ShipmentCustodyService::class)->receive(ShipmentCustodyTransfer::findOrFail($data['transfer_id']), $b, 'logistics-proof/batch-race.jpg'),
        'release' => app(ShipmentCustodyService::class)->release(ShipmentCustodyTransfer::findOrFail($data['transfer_id']), $a, 'logistics-proof/batch-race.jpg'),
        'cancel' => app(ShipmentCustodyService::class)->cancel(ShipmentCustodyTransfer::findOrFail($data['transfer_id']), $dispatcher, 'Synthetic cancellation race'),
        'notification' => app(DeliveryEventService::class)->deliverNotifications($data['event_id']),
    };
    echo json_encode(['result' => 'ok', 'id' => is_object($value) ? ($value->id ?? null) : null], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'conflict', 'fields' => array_keys($exception->errors())], JSON_THROW_ON_ERROR);
}
