<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\ShipmentCustodyService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostWindowDeliveryProgressionTest extends TestCase
{
    use RefreshDatabase;

    public static function journeys(): array
    {
        return ['normal' => ['normal'], 'same Rider retry' => ['retry'], 'replacement Rider' => ['reassigned'],
            'previously batched' => ['detached'], 'batch stop' => ['batch']];
    }

    #[DataProvider('journeys')]
    public function test_activated_assignment_arrives_corrects_proof_and_completes_after_window(string $kind): void
    {
        $this->travelTo(now(config('app.shop_timezone'))->setDate(2026, 10, 27)->setTime(11, 59));
        Storage::fake('local');
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company']);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7), 'morning_start' => '08:00', 'morning_end' => '12:00']);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'payment_status' => 'paid', 'payment_method' => 'paymongo_card']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery']);
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = User::findOrFail($profile->linked_id);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        foreach (['update-logistics-status', 'record-logistics-proof'] as $name) $rider->givePermissionTo(Permission::findOrCreate($name, 'user'));
        foreach (['resolve-logistics-exceptions', 'approve-proof-of-delivery'] as $name) $dispatcher->givePermissionTo(Permission::findOrCreate($name, 'user'));
        $this->clockInEmployee($rider);
        $this->clockInEmployee($dispatcher);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'picked_up',
            'custody_state' => 'rider', 'custody_rider_profile_id' => $profile->id, 'custody_confirmed_at' => now(),
            'customer_appointment_date' => now()->toDateString(), 'customer_appointment_window' => 'morning',
            'scheduled_delivery_date' => now()->toDateString(), 'delivery_window' => 'morning']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $profile->id, 'status' => 'assigned']);
        app(AssignmentService::class)->respondToOffer($leg, $profile, true);

        if (in_array($kind, ['retry', 'reassigned', 'detached'], true)) {
            $leg->update(['status' => 'needs_resolution']);
            $target = $kind === 'reassigned' ? RiderProfile::factory()->create(['shop_owner_id' => $shop->id]) : $profile;
            $options = [
                'request_key' => (string) Str::uuid(), 'expected_assignment_id' => $leg->assignments()->latest('id')->value('id'),
                'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'rider_profile_id' => $target->id, 'handoff_route' => 'direct',
            ];
            if ($kind === 'detached') {
                $original = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $profile->id, 'status' => 'in_progress', 'started_at' => now()]);
                $leg->update(['delivery_batch_id' => $original->id, 'stop_sequence' => 1]);
                $incidents = app(\App\Services\Logistics\DeliveryIncidentService::class);
                $incident = $incidents->report($leg->fresh(), $profile, ['type' => 'vehicle_problem', 'notes' => 'Affected stop recovery', 'photo_paths' => ['incident-evidence/stop.jpg']]);
                $incidents->resolve($incident, $shop, 'retry', 'Authorized next visit', [], $dispatcher, $options, null, ['obstacle_resolved' => true]);
                $this->assertNull($leg->fresh()->delivery_batch_id);
            } else app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Authorized next visit', $options, $dispatcher);
            app(AssignmentService::class)->respondToOffer($leg->fresh(), $target, true);
            if ($kind === 'reassigned') {
                $newRider = User::findOrFail($target->linked_id);
                foreach (['update-logistics-status', 'record-logistics-proof'] as $name) $newRider->givePermissionTo(Permission::findOrCreate($name, 'user'));
                $this->clockInEmployee($newRider);
                Storage::disk('local')->put('logistics-proof/release.jpg', 'release');
                Storage::disk('local')->put('logistics-proof/receipt.jpg', 'receipt');
                $transfer = $leg->custodyTransfers()->latest('id')->firstOrFail();
                app(ShipmentCustodyService::class)->release($transfer, $rider, 'logistics-proof/release.jpg');
                app(ShipmentCustodyService::class)->receive($transfer->fresh(), $newRider, 'logistics-proof/receipt.jpg');
                $profile = $target;
                $rider = $newRider;
            }
        }
        if ($kind === 'batch') {
            $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $profile->id, 'status' => 'in_progress', 'started_at' => now()]);
            $leg->update(['delivery_batch_id' => $batch->id, 'stop_sequence' => 1]);
            app(ShipmentLegService::class)->markOutForDelivery($leg->fresh(), $profile);
        } else {
            app(ShipmentLegService::class)->markInTransit($leg->fresh(), $profile);
        }

        $this->travelTo(now()->addMinutes(3));
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/arrivals", [
            'arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect',
        ])->assertCreated();
        $first = $this->post("/api/logistics/legs/{$leg->id}/proof", ['handoff_type' => 'delivery', 'proof_type' => 'photo',
            'idempotency_key' => (string) Str::uuid(), 'proof_file' => UploadedFile::fake()->image('proof.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('proof.id');
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/proofs/{$first}/reject", ['rejection_reason' => 'Photo is unreadable'])->assertOk();
        $second = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", ['handoff_type' => 'delivery', 'proof_type' => 'photo',
            'idempotency_key' => (string) Str::uuid(), 'replaces_proof_id' => $first, 'proof_file' => UploadedFile::fake()->image('corrected.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('proof.id');
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/proofs/{$second}/approve")->assertOk();
        $this->assertSame('delivered', $leg->fresh()->status->value);
        $this->assertSame(2, $leg->proofs()->where('handoff_type', 'delivery')->count());
        $this->assertSame(1, $leg->events()->where('event_type', 'dropoff_arrived')->count());
    }
}
