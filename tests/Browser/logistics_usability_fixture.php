<?php

require __DIR__.'/failed_delivery_fixture.php';
$purpose = $argv[1] ?? 'retail_delivery';
$data = ($argv[2] ?? 'single') === 'batch' ? batchRecoveryFixture($purpose, $argv[3] ?? 'mid') : recoveryFixture($purpose);
$leg = App\Models\Logistics\ShipmentLeg::with('shipment')->findOrFail($data['leg_id']);
$leg->update(['origin_snapshot' => [...$leg->origin_snapshot, 'latitude' => 14.45, 'longitude' => 120.98], 'destination_snapshot' => [...$leg->destination_snapshot, 'latitude' => 14.45, 'longitude' => 120.98]]);
$source = match ($leg->shipment->source_type) {
    'order' => App\Models\Order::findOrFail($leg->shipment->source_id),'order_refund' => App\Models\OrderRefund::findOrFail($leg->shipment->source_id),'repair_request' => App\Models\RepairRequest::findOrFail($leg->shipment->source_id)
};
$customer = App\Models\User::findOrFail($source->customer_id ?? $source->user_id);
if (! str_ends_with($customer->email, '@recovery.example.test')) {
    throw new RuntimeException('Synthetic customer guard failed');
}
$customer->password = 'Local-Recovery-Test-72!';
$customer->save();
$data['customer'] = ['id' => $customer->id, 'email' => $customer->email];
$data['shipment_id'] = $leg->shipment_id;
if (($argv[2] ?? 'single') === 'single') {
    $data['batch_id'] = 0;
    $data['leg_ids'] = [$leg->id];
    $data['affected_ids'] = [$leg->id];
    $data['completed_ids'] = [];
    $data['shipment_numbers'] = [(string) $leg->id => $leg->shipment->shipment_number ?? $leg->shipment_id];
    $data['initial_refunds'] = App\Models\OrderRefund::where('shop_owner_id', $data['shop_id'])->count();
}
echo json_encode($data, JSON_THROW_ON_ERROR);
