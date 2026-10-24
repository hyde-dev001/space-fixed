<?php

namespace Tests\Support;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Support\Logistics\BatchStopSnapshot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait BatchInterruptionFixture
{
    protected function interruptionFixture(string $status = 'in_progress', int $count = 3): array
    {
        Storage::fake('local');
        $this->travelTo(now()->setTime(10, 0));
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['business_type' => 'retail', 'registration_type' => 'company']);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => [1, 2, 3, 4, 5, 6, 7], 'blackout_dates' => []]);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole(Role::findOrCreate('Logistics Dispatcher', 'user'));
        foreach (['resolve-logistics-exceptions', 'manage-logistics-batches', 'assign-logistics-deliveries', 'access-logistics-shipments'] as $permission) {
            $dispatcher->givePermissionTo(Permission::findOrCreate($permission, 'user'));
        }
        $this->clockInEmployee($dispatcher);
        $a = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $b = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        foreach ([$a, $b] as $rider) {
            $user = User::findOrFail($rider->linked_id);
            foreach (['operate-logistics-deliveries', 'update-logistics-status', 'record-logistics-proof', 'access-logistics-deliveries'] as $permission) {
                $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
            }
            $this->clockInEmployee($user);
        }
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $a->id,
            'status' => $status, 'accepted_at' => now()->subHour(), 'started_at' => $status === 'in_progress' ? now()->subMinutes(30) : null,
            'assigned_stop_count' => $count, 'capacity' => 20]);
        $legs = collect(range(1, $count))->map(function ($sequence) use ($shop, $batch, $a, $status) {
            $order = Order::factory()->create(['shop_owner_id' => $shop->id]);
            $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery']);
            $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id, 'delivery_batch_id' => $batch->id,
                'stop_sequence' => $sequence, 'status' => $status === 'accepted' ? 'assigned' : ($sequence === 1 ? 'in_transit' : 'picked_up'),
                'scheduled_delivery_date' => $batch->delivery_date, 'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
                'picked_up_at' => $status === 'accepted' ? null : now()->subMinutes(30),
                'custody_state' => $status === 'accepted' ? 'unknown' : 'rider',
                'custody_rider_profile_id' => $status === 'accepted' ? null : $a->id,
                'custody_confirmed_at' => $status === 'accepted' ? null : now()->subMinutes(30)]);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $a->id, 'status' => 'accepted']);
            return $leg;
        });
        $legs->each->load('shipment');
        $batch->update(['stop_snapshot' => BatchStopSnapshot::fromLegs($legs)]);
        return compact('shop', 'dispatcher', 'a', 'b', 'batch', 'legs');
    }

    protected function reportInterruption(array $fixture, ?string $key = null)
    {
        return $this->actingAs(User::findOrFail($fixture['a']->linked_id), 'user')->postJson(
            "/api/logistics/batches/{$fixture['batch']->id}/incidents", [
                'notes' => 'Vehicle cannot safely continue. Synthetic test.', 'request_key' => $key ?? (string) Str::uuid(),
                'photo_files' => [UploadedFile::fake()->image('vehicle.jpg')],
            ]);
    }
}
