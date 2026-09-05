<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\Adapters\ActiveLogisticsRecoveryAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ActiveLogisticsRecoveryAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_rider_and_dispatcher_recovery_are_projected_with_current_boundaries(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $riderLeg = $this->failedLeg($shop);
        $rider = $this->rider($shop);
        $riderAssignment = $riderLeg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now()->subHours(12),
            'accepted_at' => now()->subHours(11),
        ]);
        $dispatcherLeg = $this->failedLeg($shop);
        $dispatcherAssignment = $dispatcherLeg->assignments()->create([
            'assignment_type' => 'external_courier',
            'status' => 'assigned',
            'assigned_at' => now()->subHours(10),
        ]);

        $result = $this->adapter()->read($shop, $this->query());

        $this->assertSame(2, $result->qualifyingCount);
        $this->assertSame([$riderLeg->id, $dispatcherLeg->id], array_map(
            static fn ($item): int => $item->sourceId,
            $result->items,
        ));

        $riderItem = $result->items[0];
        $this->assertSame('logistics_failure:'.$riderLeg->id.':logistics_recovery_waiting', $riderItem->attentionKey);
        $this->assertSame('rider', $riderItem->waitingOn);
        $this->assertSame('waiting_on_others', $riderItem->primaryBucket);
        $this->assertSame('logistics', $riderItem->coverageSource);
        $this->assertFalse($riderItem->ownerActionRequired);
        $this->assertSame('high', $riderItem->priorityTier);
        $this->assertSame('high', $riderItem->materialityTier);
        $this->assertSame($riderLeg->failed_at->toISOString(), $riderItem->urgencyAt);
        $this->assertSame($riderAssignment->assigned_at->toISOString(), $riderItem->actionableSince);
        $this->assertStringContainsString('shipment='.$riderLeg->shipment_id, $riderItem->destinationUrl);
        $this->assertStringContainsString('leg='.$riderLeg->id, $riderItem->destinationUrl);

        $this->assertSame('dispatcher', $result->items[1]->waitingOn);
        $this->assertSame($dispatcherAssignment->assigned_at->toISOString(), $result->items[1]->actionableSince);
    }

    public function test_owner_action_recovery_exhaustion_and_terminal_states_do_not_enter_waiting(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $ownerActionLeg = $this->failedLeg($shop);
        $return = ShipmentLeg::factory()->create([
            'shipment_id' => $ownerActionLeg->shipment_id,
            'leg_type' => 'return_to_shop',
            'return_for_leg_id' => $ownerActionLeg->id,
            'status' => 'picked_up',
        ]);
        $returnRider = $this->rider($shop);
        $return->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $returnRider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $return->proofs()->create([
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'review_status' => 'rider_confirmed',
        ]);
        $this->failedLeg($shop);
        $delivered = $this->failedLeg($shop);
        $delivered->update(['status' => 'delivered']);
        $cancelled = $this->failedLeg($shop);
        $cancelled->update(['status' => 'cancelled']);
        $returned = $this->failedLeg($shop);
        $returned->update(['resolution_type' => 'returned']);

        $result = $this->adapter()->read($shop, $this->query());

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->qualifyingCount);
    }

    public function test_reassignment_updates_waiting_role_and_boundary(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $leg = $this->failedLeg($shop);
        $firstRider = $this->rider($shop);
        $first = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $firstRider->id,
            'status' => 'accepted',
            'assigned_at' => now()->subHours(12),
        ]);

        $first->update(['status' => 'cancelled', 'cancelled_at' => '2026-08-16 09:00:00']);
        $second = $leg->assignments()->create([
            'assignment_type' => 'external_courier',
            'status' => 'assigned',
            'assigned_at' => now()->subHours(6),
        ]);

        $item = $this->adapter()->read($shop, $this->query())->items[0];

        $this->assertSame($leg->id, $item->sourceId);
        $this->assertSame('dispatcher', $item->waitingOn);
        $this->assertSame($second->assigned_at->toISOString(), $item->actionableSince);
        $this->assertNotSame($first->assigned_at->toISOString(), $item->actionableSince);
    }

    public function test_invalid_or_contradictory_current_responsibility_fails_health(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $inactiveLeg = $this->failedLeg($shop);
        $inactiveRider = $this->rider($shop, ['active' => false]);
        $inactiveLeg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $inactiveRider->id,
            'status' => 'accepted',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('logistics_responsibility_inconsistent');

        $this->adapter()->read($shop, $this->query());
    }

    public function test_invalid_batch_and_historical_event_alone_never_project_waiting(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $batchLeg = $this->failedLeg($shop);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'completed',
        ]);
        $batchLeg->update(['delivery_batch_id' => $batch->id]);

        $historicalLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'pending',
        ]);
        DeliveryEvent::factory()->create([
            'shipment_id' => $historicalLeg->shipment_id,
            'shipment_leg_id' => $historicalLeg->id,
            'event_type' => 'overdue_return_receipt',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('logistics_responsibility_inconsistent');

        $this->adapter()->read($shop, $this->query());
    }

    public function test_cross_shop_legs_are_excluded_and_projection_queries_remain_bounded(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $foreign = $this->shop(['max_delivery_attempts' => 1]);
        $this->activeDispatcherLeg($foreign);
        $this->activeDispatcherLeg($shop);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $this->adapter()->read($shop, new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            coverage: 'logistics',
            candidateLimit: 1,
        ));

        $this->assertCount(1, $result->items);
        $this->assertSame(1, $result->qualifyingCount);
        $this->assertLessThanOrEqual(16, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function adapter(): ActiveLogisticsRecoveryAttentionAdapter
    {
        return app(ActiveLogisticsRecoveryAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(
            bucket: 'waiting_on_others',
            coverage: 'logistics',
            candidateLimit: 50,
        );
    }

    /** @param array<string, mixed> $settings */
    private function shop(array $settings = []): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        LogisticsSetting::create(array_replace(['shop_owner_id' => $shop->id], $settings));

        return $shop;
    }

    /** @param array<string, mixed> $overrides */
    private function failedLeg(ShopOwner $shop, array $overrides = []): ShipmentLeg
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create(array_merge([
            'shipment_id' => $shipment->id,
            'status' => 'needs_resolution',
            'failed_at' => now()->subDay(),
            'resolution_type' => null,
        ], $overrides));
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'attempt_number' => 1,
            'attempted_at' => now()->subDay(),
        ]);

        return $leg;
    }

    private function activeDispatcherLeg(ShopOwner $shop): ShipmentLeg
    {
        $leg = $this->failedLeg($shop);
        $leg->assignments()->create([
            'assignment_type' => 'external_courier',
            'status' => 'assigned',
            'assigned_at' => now()->subHour(),
        ]);

        return $leg;
    }

    /** @param array<string, mixed> $overrides */
    private function rider(ShopOwner $shop, array $overrides = []): RiderProfile
    {
        return RiderProfile::factory()->create(array_merge([
            'shop_owner_id' => $shop->id,
        ], $overrides));
    }
}
