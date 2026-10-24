<?php

require __DIR__.'/failed_delivery_environment.php';

use App\Models\{Employee, Order, OrderRefund, RepairRequest, ShopOwner, User};
use App\Models\Logistics\{DeliveryAssignment, LogisticsSetting, RiderProfile, Shipment, ShipmentLeg};
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\{Permission, Role};

function recoveryFixture(string $purpose, bool $mfa = true): array
{
    $shop = ShopOwner::factory()->approved()->withLogistics()->create(['business_type' => 'both', 'registration_type' => 'company']);
    $shop->forceFill(collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->flatMap(fn ($day) => [$day.'_open' => '00:00:00', $day.'_close' => '23:59:00'])->all())->save();
    LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7), 'max_delivery_attempts' => 2]);
    $actors = [];
    foreach (['dispatcher', 'a', 'b'] as $name) {
        $roleName = $name === 'dispatcher' ? 'Logistics Dispatcher' : 'Logistics Rider';
        $role = Role::findOrCreate($roleName, 'user');
        $permissions = ['access-logistics-dashboard', 'view-logistics-shipments', 'update-logistics-status', 'record-logistics-proof', 'operate-logistics-deliveries', 'operate-assigned-batches', 'access-notification-center', 'access-profile'];
        if ($name === 'dispatcher') $permissions = array_values(array_diff($permissions, ['operate-logistics-deliveries', 'operate-assigned-batches']));
        if ($name === 'dispatcher') $permissions = [...$permissions, 'assign-logistics-deliveries', 'resolve-logistics-exceptions', 'manage-logistics-batches', 'approve-proof-of-delivery'];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'user');
        if ($role->permissions()->count() === 0) $role->syncPermissions($permissions);
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'email' => $name.'-'.$shop->id.'@recovery.example.test', 'password' => 'Local-Recovery-Test-72!', 'role' => 'STAFF', 'status' => 'active', 'force_password_change' => false]);
        $user->assignRole($role);
        if ($mfa) $user->forceFill(['employee_totp_secret' => 'JBSWY3DPEHPK3PXP', 'employee_totp_enabled_at' => now()])->save();
        Employee::factory()->active()->create(['shop_owner_id' => $shop->id, 'email' => $user->email, 'department' => 'Logistics', 'position' => $roleName]);
        $actors[$name] = ['id' => $user->id, 'email' => $user->email];
        if ($name !== 'dispatcher') $actors[$name]['rider_id'] = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $user->id, 'name' => 'Test Rider '.strtoupper($name)])->id;
    }
    $customer = User::factory()->create(['name' => 'Synthetic Customer', 'email' => 'customer-'.\Illuminate\Support\Str::uuid().'@recovery.example.test']);
    $sourceType = 'order';
    $source = Order::factory()->create(['shop_owner_id' => $shop->id, 'customer_id' => $customer->id, 'payment_method' => 'paymongo_card', 'payment_status' => 'paid', 'status' => 'shipped', 'delivery_method' => 'shop_owned']);
    if ($purpose === 'refund_return') {
        $sourceType = 'order_refund';
        $source = OrderRefund::factory()->create(['order_id' => $source->id, 'shop_owner_id' => $shop->id, 'customer_id' => $customer->id, 'finance_status' => 'approved', 'shop_owner_status' => 'approved', 'return_status' => 'pending_staff_pickup', 'return_source' => 'staff', 'staff_return_carrier' => 'Shop-owned logistics']);
    } elseif (str_starts_with($purpose, 'repair_')) {
        $sourceType = 'repair_request';
        $source = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'user_id' => $customer->id, 'status' => $purpose === 'repair_return' ? 'shipped' : 'new_request', 'payment_status' => 'completed', 'payment_status_derived' => 'completed', 'total_paid_amount' => 1000, 'delivery_method' => 'delivery', 'total' => 1000, 'return_delivery_method' => $purpose === 'repair_return' ? 'shop_delivery' : null]);
    }
    $collection = in_array($purpose, ['repair_pickup', 'refund_return'], true);
    $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => $sourceType, 'source_id' => $source->id, 'purpose' => $purpose, 'status' => 'active']);
    $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id, 'leg_type' => $purpose === 'refund_return' ? 'return_to_shop' : ($collection ? 'inbound' : 'outbound'),
        'status' => $collection ? 'assigned' : 'in_transit', 'picked_up_at' => $collection ? null : now(),
        'custody_state' => $collection ? 'unknown' : 'rider', 'custody_rider_profile_id' => $collection ? null : $actors['a']['rider_id'], 'custody_confirmed_at' => $collection ? null : now(),
        'origin_snapshot' => ['type' => $collection ? 'customer' : 'shop', 'name' => 'Synthetic origin'], 'destination_snapshot' => ['type' => $collection ? 'shop' : 'customer', 'name' => 'Synthetic destination']]);
    $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $actors['a']['rider_id'], 'status' => 'accepted']);
    return ['shop_id' => $shop->id, 'leg_id' => $leg->id, 'assignment_id' => $assignment->id, 'purpose' => $purpose, 'collection' => $collection, 'actors' => $actors];
}

function batchRecoveryFixture(string $purpose, string $stage = 'mid'): array
{
    $data = recoveryFixture($purpose);
    $shop = ShopOwner::findOrFail($data['shop_id']);
    // Keep the synthetic confirmed-window scenario independent of local wall-clock time.
    LogisticsSetting::where('shop_owner_id', $shop->id)->update(['morning_start' => '00:00', 'morning_end' => '23:59']);
    $leg = ShipmentLeg::with('shipment')->findOrFail($data['leg_id']);
    $legs = collect([$leg]);
    for ($index = 1; $index < 3; $index++) {
        $customer = User::factory()->create(['name' => 'Synthetic batch customer', 'email' => 'customer-'.\Illuminate\Support\Str::uuid().'@recovery.example.test']);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'customer_id' => $customer->id,
            'delivery_method' => 'shop_owned', 'status' => 'shipped', 'payment_method' => 'paymongo_card', 'payment_status' => 'paid']);
        $source = $order;
        if ($purpose === 'refund_return') {
            $source = OrderRefund::factory()->create(['order_id' => $order->id, 'shop_owner_id' => $shop->id, 'customer_id' => $customer->id,
                'finance_status' => 'approved', 'shop_owner_status' => 'approved', 'return_status' => 'pending_staff_pickup', 'return_source' => 'staff', 'staff_return_carrier' => 'Shop-owned logistics']);
        } elseif (str_starts_with($purpose, 'repair_')) {
            $source = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'user_id' => $customer->id,
                'status' => $purpose === 'repair_return' ? 'shipped' : 'new_request', 'payment_status' => 'completed',
                'payment_status_derived' => 'completed', 'total_paid_amount' => 1000, 'total' => 1000, 'delivery_method' => 'delivery',
                'return_delivery_method' => $purpose === 'repair_return' ? 'shop_delivery' : null]);
        }
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => $leg->shipment->source_type,
            'source_id' => $source->id, 'purpose' => $purpose, 'status' => 'active']);
        $legs->push(ShipmentLeg::factory()->create(['shop_owner_id' => $shop->id, 'shipment_id' => $shipment->id,
            'leg_type' => $leg->leg_type, 'status' => 'pending', 'origin_snapshot' => $leg->origin_snapshot,
            'destination_snapshot' => $leg->destination_snapshot]));
    }
    $schedule = ['status' => 'pending', 'schedule_status' => 'scheduled', 'scheduled_delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'morning'];
    $leg->assignments()->update(['status' => 'cancelled']);
    foreach ($legs as $item) $item->update($schedule);
    $service = app(App\Services\Logistics\BatchDispatchService::class);
    $batch = $service->createDraft($shop, $schedule['scheduled_delivery_date'], 'morning', $legs->pluck('id')->all());
    $rider = RiderProfile::findOrFail($data['actors']['a']['rider_id']);
    $service->offer($batch, $rider, $shop);
    $service->accept($batch->fresh(), $rider);
    if ($stage !== 'before') $service->start($batch->fresh(), $rider);
    $completed = $stage === 'last' ? 2 : ($stage === 'mid' ? 1 : 0);
    foreach ($legs->values() as $index => $item) {
        $visited = $index < $completed;
        $collected = $stage !== 'before';
        $item->update(['status' => $visited ? 'delivered' : ($collected ? ($index === $completed ? 'in_transit' : 'picked_up') : 'assigned'),
            'destination_snapshot' => [...$item->destination_snapshot, 'latitude' => 14.45, 'longitude' => 120.98],
            'rider_progress_state' => $visited ? 'rider_released' : 'active', 'picked_up_at' => $collected ? now()->subMinutes(20) : null,
            'custody_state' => $collected ? 'rider' : 'unknown', 'custody_rider_profile_id' => $collected ? $rider->id : null,
            'custody_confirmed_at' => $collected ? now()->subMinutes(20) : null, 'delivered_at' => $visited ? now()->subMinutes(5) : null]);
        if ($visited) $item->assignments()->where('status', 'accepted')->update(['status' => 'completed']);
    }
    $data['batch_id'] = $batch->id;
    $data['leg_ids'] = $legs->pluck('id')->all();
    $data['completed_ids'] = $legs->take($completed)->pluck('id')->all();
    $data['affected_ids'] = $legs->slice($completed)->pluck('id')->values()->all();
    $data['leg_id'] = $data['affected_ids'][0];
    $data['stage'] = $stage;
    if ($stage === 'appointment') {
        ShipmentLeg::findOrFail($data['leg_id'])->update(['customer_appointment_date' => now(config('app.shop_timezone'))->addDay()->toDateString(), 'customer_appointment_window' => 'morning']);
    }
    $data['initial_refunds'] = OrderRefund::where('shop_owner_id', $shop->id)->count();
    $data['shipment_numbers'] = $legs->mapWithKeys(fn ($item) => [$item->id => $item->shipment->shipment_number ?? $item->shipment_id])->all();
    return $data;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && ($argv[1] ?? '') === '--batch') {
    echo json_encode(batchRecoveryFixture($argv[2] ?? 'retail_delivery', $argv[3] ?? 'mid'), JSON_THROW_ON_ERROR);
} elseif (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(recoveryFixture($argv[1] ?? 'retail_delivery', !in_array('--unenrolled', $argv, true)), JSON_THROW_ON_ERROR);
}
