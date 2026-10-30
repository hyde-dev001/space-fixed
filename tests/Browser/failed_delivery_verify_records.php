<?php

require __DIR__.'/failed_delivery_environment.php';

use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function checkRecoveryRecord(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$mfa = in_array('--mfa', $argv, true);
$observations = json_decode(file_get_contents($testDirectory.($mfa ? '/browser-mfa-results.json' : '/browser-results.json')), true, flags: JSON_THROW_ON_ERROR);
checkRecoveryRecord(count($observations) === ($mfa ? 4 : 8), 'The browser matrix is incomplete.');
$verified = [];
foreach ($observations as $observation) {
    $leg = ShipmentLeg::with(['shipment', 'attempts', 'proofs', 'custodyTransfers'])->findOrFail($observation['leg_id']);
    $case = $observation['case'] ?? $observation['purpose'];
    $repeated = str_ends_with($case, '@repeat_failure');
    $appointment = str_ends_with($case, '@appointment');
    checkRecoveryRecord($leg->status->value === ($repeated || $appointment ? 'needs_resolution' : 'delivered'), 'Unexpected persisted leg state: '.$case);
    checkRecoveryRecord($leg->attempts->count() === ($repeated ? 2 : 1), 'Unexpected attempt history: '.$case);
    checkRecoveryRecord($leg->attempts->pluck('delivery_assignment_id')->unique()->count() === $leg->attempts->count(), 'An execution lost its distinct failure: '.$case);
    $customerId = match ($leg->shipment->source_type) {
        'order' => Order::findOrFail($leg->shipment->source_id)->customer_id,
        'order_refund' => OrderRefund::findOrFail($leg->shipment->source_id)->customer_id,
        'repair_request' => RepairRequest::findOrFail($leg->shipment->source_id)->user_id,
    };
    $dispatcherIds = User::where('shop_owner_id', $leg->shop_owner_id)->whereHas('roles', fn ($q) => $q->where('name', 'Logistics Dispatcher'))->pluck('id');
    checkRecoveryRecord($dispatcherIds->isNotEmpty(), 'The fixture has no dispatcher.');
    $failureEvents = $leg->events()->whereIn('event_type', ['delivery_attempt_failed', 'pickup_attempt_failed'])->get();
    foreach ($leg->attempts as $attempt) {
        $events = $failureEvents->filter(fn ($event) => (int) ($event->metadata['delivery_attempt_id'] ?? 0) === $attempt->id);
        checkRecoveryRecord($events->isNotEmpty(), 'Missing persisted failure event: '.$case);
        checkRecoveryRecord($events->contains(fn ($event) => $event->visibility === 'customer'), 'Missing customer failure event: '.$case);
        foreach (array_unique([$customerId, ...$dispatcherIds->all()]) as $userId) {
            $keys = $events->map(fn ($event) => hash('sha256', implode(':', ['logistics', $userId, $leg->shipment_id, $leg->id, $event->event_type, 'delivery_attempt_id='.$attempt->id])))->unique();
            $notices = Notification::whereIn('logistics_delivery_key', $keys)->get();
            checkRecoveryRecord($notices->count() === 1, 'Missing/duplicate failure notice: '.$case);
            checkRecoveryRecord($notices->first()->shop_id === $leg->shop_owner_id, 'Cross-shop notification: '.$case);
            checkRecoveryRecord(str_starts_with($notices->first()->action_url, $userId === $customerId ? '/tracking/shipments/' : '/erp/logistics/shipments'), 'Invalid failure action link: '.$case);
        }
        checkRecoveryRecord($attempt->file_path && Storage::disk('local')->exists($attempt->file_path), 'Missing private failure proof: '.$case);
        checkRecoveryRecord(!Storage::disk('public')->exists($attempt->file_path), 'Failure proof is public: '.$case);
    }
    foreach ($leg->proofs as $proof) {
        checkRecoveryRecord(Storage::disk('local')->exists($proof->file_path), 'Missing private handoff proof: '.$case);
        checkRecoveryRecord(!Storage::disk('public')->exists($proof->file_path), 'Handoff proof is public: '.$case);
    }
    foreach ($leg->custodyTransfers as $transfer) {
        checkRecoveryRecord($transfer->status === 'received' && $transfer->released_at && $transfer->received_at, 'Incomplete recorded transfer: '.$case);
        checkRecoveryRecord($transfer->released_by_user_id !== $transfer->received_by_user_id && $transfer->release_proof_id && $transfer->receipt_proof_id, 'Missing independent confirmations: '.$case);
    }
    if ($appointment) checkRecoveryRecord($leg->customer_appointment_date->isFuture(), 'Customer appointment was lost.');
    if ($leg->shipment->source_type === 'order_refund') {
        $refund = OrderRefund::findOrFail($leg->shipment->source_id);
        checkRecoveryRecord($refund->return_status === 'in_transit' && !$refund->return_confirmed_at && $refund->payout_status !== 'completed', 'Receipt fabricated inspection/payment.');
    }
    $verified[] = ['case' => $case, 'status' => $leg->status->value, 'distinct_attempts' => $leg->attempts->count(),
        'confirmed_transfers' => $leg->custodyTransfers->count(), 'private_proofs' => $leg->proofs->count(), 'failure_recipient_checks' => 'passed'];
}
echo json_encode($verified, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
