<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RetryResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company']);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7)]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'needs_resolution', 'resolution_type' => 'retry', 'picked_up_at' => now(),
            'custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now(),
            'scheduled_delivery_date' => now()->addDays(3)->toDateString(), 'delivery_window' => 'afternoon']);
        $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        return [$shop, $rider, $leg, $assignment];
    }

    public function test_internal_planning_date_allows_an_operationally_eligible_early_retry(): void
    {
        [, $rider, $leg] = $this->fixture();
        $this->assertSame('in_transit', app(ShipmentLegService::class)->markInTransit($leg, $rider)->status->value);
    }

    public function test_unknown_legacy_custody_cannot_authorize_another_retry(): void
    {
        [, , $leg] = $this->fixture();
        $leg->update(['resolution_type' => null, 'custody_state' => 'unknown', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => null]);
        $this->expectException(ValidationException::class);
        app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Another visit');
    }

    public function test_early_retry_still_requires_rider_availability_today(): void
    {
        [, $rider, $leg] = $this->fixture();
        $rider->update(['work_days' => [now(config('app.shop_timezone'))->dayOfWeekIso === 7 ? 1 : now(config('app.shop_timezone'))->dayOfWeekIso + 1]]);
        $this->expectException(ValidationException::class);
        app(ShipmentLegService::class)->markInTransit($leg, $rider->fresh());
    }

    public function test_single_assignment_respects_rider_work_days(): void
    {
        [$shop, $rider, $leg, $assignment] = $this->fixture();
        $assignment->update(['status' => 'cancelled']);
        $leg->update(['status' => 'pending']);
        $scheduledDay = $leg->scheduled_delivery_date->dayOfWeekIso;
        $rider->update(['work_days' => [$scheduledDay === 7 ? 1 : $scheduledDay + 1]]);
        $this->expectException(ValidationException::class);
        app(AssignmentService::class)->assignInternalRider($leg->fresh(), $rider->fresh(), $shop);
    }

    public function test_customer_confirmed_appointment_blocks_a_normal_early_start(): void
    {
        [, $rider, $leg] = $this->fixture();
        $leg->update(['status' => 'picked_up', 'resolution_type' => null,
            'customer_appointment_date' => now()->addDays(3)->toDateString(), 'customer_appointment_window' => 'afternoon', 'appointment_agreed_at' => now()]);
        $this->expectException(ValidationException::class);
        app(ShipmentLegService::class)->markInTransit($leg->fresh(), $rider);
    }

    public function test_declining_a_retry_keeps_resolution_and_physical_custody_explicit(): void
    {
        [, $rider, $leg] = $this->fixture();
        $leg->update(['resolution_type' => null]);
        app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Another visit');
        app(AssignmentService::class)->respondToOffer($leg->fresh(), $rider, false, 'Unable to take this visit');
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertNull($leg->fresh()->resolution_type);
        $this->assertSame($rider->id, $leg->fresh()->custody_rider_profile_id);
    }

    public function test_dispatcher_selects_date_and_new_rider_without_transferring_custody(): void
    {
        [$shop, $a, $leg, $assignment] = $this->fixture();
        $leg->update(['resolution_type' => null]);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $this->clockInEmployee($dispatcher);
        $b = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $data = ['reason' => 'Customer agreed to another visit', 'delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'afternoon', 'rider_profile_id' => $b->id, 'expected_assignment_id' => $assignment->id, 'request_key' => (string) Str::uuid(), 'handoff_route' => 'direct'];
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/resolve/retry", $data)->assertOk();
        $this->assertSame($b->id, $leg->assignments()->latest('id')->value('rider_profile_id'));
        $this->assertSame($a->id, $leg->fresh()->custody_rider_profile_id);
        $this->assertSame($data['delivery_date'], $leg->fresh()->scheduled_delivery_date->toDateString());
        $this->assertSame(1, $leg->custodyTransfers()->where('status', 'requested')->count());
        $this->postJson("/api/logistics/legs/{$leg->id}/resolve/retry", $data)->assertOk();
        $this->assertSame(2, $leg->assignments()->count());
    }
    public function test_replayed_request_cannot_silently_change_internal_planning_into_a_customer_appointment(): void
    {
        [$shop, $rider, $leg, $assignment] = $this->fixture();
        $leg->update(['resolution_type' => null]);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $options = ['delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'afternoon', 'rider_profile_id' => $rider->id, 'expected_assignment_id' => $assignment->id, 'request_key' => (string) Str::uuid(), 'handoff_route' => 'direct'];
        $service = app(ShipmentLegService::class);
        $service->resolveRetry($leg, 'Another visit', $options, $dispatcher);
        $this->expectException(ValidationException::class);
        $service->resolveRetry($leg->fresh(), 'Another visit', [...$options, 'appointment_confirmed' => true, 'appointment_agreement_note' => 'Changed customer agreement'], $dispatcher);
    }
}
