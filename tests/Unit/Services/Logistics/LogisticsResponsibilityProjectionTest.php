<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\LogisticsResponsibilityProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LogisticsResponsibilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_receipt_waiting_for_owner_is_a_decision_not_an_exception(): void
    {
        $shop = $this->shop();
        $original = $this->leg($shop, ['status' => 'needs_resolution']);
        $return = $this->leg($shop, [
            'shipment_id' => $original->shipment_id,
            'leg_type' => 'return_to_shop',
            'return_for_leg_id' => $original->id,
            'status' => 'picked_up',
        ]);
        $rider = $this->rider($shop);
        $return->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $return->proofs()->create([
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'review_status' => 'rider_confirmed',
            'confirmed_by_type' => RiderProfile::class,
            'confirmed_by_id' => $rider->id,
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($return->fresh());

        self::assertTrue($result->ownerActionRequired);
        self::assertNull($result->deterministicResponsibleParty);
        self::assertTrue($result->recoveryPathActive);
        self::assertFalse($result->recoveryPathExhausted);
        self::assertTrue($result->materialExceptionActive);
        self::assertNull($result->healthReason);
    }

    public function test_active_rider_owns_retry_recovery(): void
    {
        $shop = $this->shop();
        $leg = $this->leg($shop, [
            'status' => 'needs_resolution',
            'resolution_type' => 'retry',
            'failed_at' => now()->subHour(),
        ]);
        $rider = $this->rider($shop);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now()->subMinutes(10),
            'accepted_at' => now()->subMinutes(5),
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($leg->fresh());

        self::assertFalse($result->ownerActionRequired);
        self::assertSame('rider', $result->deterministicResponsibleParty);
        self::assertTrue($result->recoveryPathActive);
        self::assertFalse($result->recoveryPathExhausted);
        self::assertTrue($result->materialExceptionActive);
        self::assertNull($result->healthReason);
    }

    public function test_dispatcher_owns_a_live_batch_without_a_current_rider_assignment(): void
    {
        $shop = $this->shop();
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'offered',
        ]);
        $leg = $this->leg($shop, [
            'status' => 'assigned',
            'delivery_batch_id' => $batch->id,
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($leg->fresh());

        self::assertSame('dispatcher', $result->deterministicResponsibleParty);
        self::assertTrue($result->recoveryPathActive);
        self::assertFalse($result->recoveryPathExhausted);
        self::assertNull($result->healthReason);
    }

    public function test_exhausted_unowned_delivery_is_material_and_has_no_responsible_party(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 2]);
        $leg = $this->leg($shop, [
            'status' => 'needs_resolution',
            'failed_at' => now()->subDay(),
            'resolution_type' => null,
        ]);
        $leg->attempts()->createMany([
            ['attempt_type' => 'delivery', 'status' => 'failed', 'attempt_number' => 1, 'attempted_at' => now()->subDays(2)],
            ['attempt_type' => 'delivery', 'status' => 'failed', 'attempt_number' => 2, 'attempted_at' => now()->subDay()],
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($leg->fresh());

        self::assertFalse($result->ownerActionRequired);
        self::assertNull($result->deterministicResponsibleParty);
        self::assertFalse($result->recoveryPathActive);
        self::assertTrue($result->recoveryPathExhausted);
        self::assertTrue($result->materialExceptionActive);
        self::assertNull($result->healthReason);
    }

    public function test_inactive_rider_does_not_create_legitimate_ownership_or_exception(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 2]);
        $leg = $this->leg($shop, [
            'status' => 'needs_resolution',
            'failed_at' => now()->subDay(),
        ]);
        $rider = $this->rider($shop, ['active' => false]);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now()->subDay(),
            'accepted_at' => now()->subDay(),
        ]);
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'attempt_number' => 2,
            'attempted_at' => now()->subDay(),
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($leg->fresh());

        self::assertNull($result->deterministicResponsibleParty);
        self::assertFalse($result->materialExceptionActive);
        self::assertSame('invalid_active_assignment', $result->healthReason);
    }

    public function test_conflicting_active_assignments_are_health_failures(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $leg = $this->leg($shop, [
            'status' => 'needs_resolution',
            'failed_at' => now()->subHour(),
        ]);
        $first = $this->rider($shop);
        $second = $this->rider($shop);
        foreach ([$first, $second] as $rider) {
            $leg->assignments()->create([
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
                'status' => 'accepted',
                'assigned_at' => now()->subMinutes(5),
                'accepted_at' => now()->subMinutes(4),
            ]);
        }
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'attempt_number' => 1,
            'attempted_at' => now()->subHour(),
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($leg->fresh());

        self::assertNull($result->deterministicResponsibleParty);
        self::assertFalse($result->materialExceptionActive);
        self::assertSame('contradictory_active_assignments', $result->healthReason);
    }

    public function test_existing_return_path_prevents_original_leg_from_being_unowned(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $original = $this->leg($shop, [
            'status' => 'needs_resolution',
            'failed_at' => now()->subDay(),
            'resolution_type' => 'return_required',
        ]);
        $return = $this->leg($shop, [
            'shipment_id' => $original->shipment_id,
            'leg_type' => 'return_to_shop',
            'return_for_leg_id' => $original->id,
            'status' => 'picked_up',
        ]);
        $rider = $this->rider($shop);
        $return->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $original->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'attempt_number' => 1,
            'attempted_at' => now()->subDay(),
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($original->fresh());

        self::assertSame('rider', $result->deterministicResponsibleParty);
        self::assertTrue($result->recoveryPathActive);
        self::assertFalse($result->recoveryPathExhausted);
        self::assertNull($result->healthReason);
    }

    public function test_historical_overdue_event_does_not_create_current_exception(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 2]);
        $leg = $this->leg($shop, ['status' => 'pending']);
        DeliveryEvent::factory()->create([
            'shipment_id' => $leg->shipment_id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'overdue_return_receipt',
            'created_at' => now()->subDay(),
        ]);

        $result = app(LogisticsResponsibilityProjection::class)->project($leg->fresh());

        self::assertFalse($result->ownerActionRequired);
        self::assertNull($result->deterministicResponsibleParty);
        self::assertFalse($result->recoveryPathActive);
        self::assertFalse($result->recoveryPathExhausted);
        self::assertFalse($result->materialExceptionActive);
        self::assertNull($result->healthReason);
    }

    public function test_bulk_projection_uses_preloaded_relations_without_per_leg_queries(): void
    {
        $shop = $this->shop();
        $legIds = collect([
            $this->leg($shop, ['status' => 'pending']),
            $this->leg($shop, ['status' => 'pending']),
        ])->pluck('id');
        $legs = ShipmentLeg::query()->whereIn('id', $legIds)->with([
            'shipment.shopOwner.logisticsSetting',
            'assignments.riderProfile',
            'attempts',
            'deliveryBatch.riderProfile',
            'returnForLeg',
            'returnLeg.assignments.riderProfile',
            'returnLeg.proofs',
            'proofs',
        ])->get();

        $queries = 0;
        $listener = function () use (&$queries): void {
            $queries++;
        };
        $this->app['db']->listen($listener);

        $results = app(LogisticsResponsibilityProjection::class)->projectMany($legs);

        self::assertCount(2, $results);
        self::assertSame(0, $queries);
    }

    private function shop(array $settings = []): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id] + $settings);

        return $shop;
    }

    private function rider(ShopOwner $shop, array $attributes = []): RiderProfile
    {
        return RiderProfile::factory()->create(['shop_owner_id' => $shop->id] + $attributes);
    }

    private function leg(ShopOwner $shop, array $attributes = []): ShipmentLeg
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
        ]);

        return ShipmentLeg::factory()->create(array_replace(['shipment_id' => $shipment->id], $attributes));
    }
}
