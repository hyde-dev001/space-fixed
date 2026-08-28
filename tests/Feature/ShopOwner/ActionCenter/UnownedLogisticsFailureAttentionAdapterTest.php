<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\Adapters\UnownedLogisticsFailureAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnownedLogisticsFailureAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_exhausted_unowned_failures_are_projected(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $unowned = $this->failedLeg($shop);
        $active = $this->failedLeg($shop);
        $rider = $this->rider($shop);
        $active->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $return = $this->failedLeg($shop);
        $returnLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $return->shipment_id,
            'leg_type' => 'return_to_shop',
            'return_for_leg_id' => $return->id,
            'status' => 'picked_up',
        ]);
        $returnRider = $this->rider($shop);
        $returnLeg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $returnRider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $returnLeg->proofs()->create([
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'review_status' => 'rider_confirmed',
        ]);

        $result = $this->adapter()->read($shop, $this->query());

        self::assertCount(1, $result->items);
        self::assertSame($unowned->id, $result->items[0]->sourceId);
        self::assertSame('logistics_failure', $result->items[0]->sourceType);
        self::assertSame('unowned_delivery_failure', $result->items[0]->category);
        self::assertSame('logistics', $result->items[0]->coverageSource);
        self::assertSame('urgent_exceptions', $result->items[0]->primaryBucket);
        self::assertFalse($result->items[0]->ownerActionRequired);
        self::assertStringStartsWith('/shop-owner/logistics/shipments?', $result->items[0]->destinationUrl);
        self::assertStringContainsString('leg='.$unowned->id, $result->items[0]->destinationUrl);
    }

    public function test_owner_return_confirmation_is_not_duplicated_as_an_exception(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $original = $this->failedLeg($shop);
        $return = ShipmentLeg::factory()->create([
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
        ]);

        $result = $this->adapter()->read($shop, $this->query());

        self::assertCount(0, $result->items);
        self::assertSame(0, $result->qualifyingCount);
    }

    public function test_resolution_reassignment_and_terminal_state_remove_the_item_on_next_read(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $leg = $this->failedLeg($shop);
        $adapter = $this->adapter();

        self::assertCount(1, $adapter->read($shop, $this->query())->items);

        $leg->update(['resolution_type' => 'retry']);
        $rider = $this->rider($shop);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        self::assertCount(0, $adapter->read($shop, $this->query())->items);

        $leg->update(['status' => 'cancelled', 'resolution_type' => 'loss_confirmed']);
        self::assertCount(0, $adapter->read($shop, $this->query())->items);
    }

    public function test_historical_overdue_events_do_not_qualify_a_current_non_failed_leg(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'pending',
        ]);
        DeliveryEvent::factory()->create([
            'shipment_id' => $leg->shipment_id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'overdue_return_receipt',
            'created_at' => now()->subDay(),
        ]);

        $result = $this->adapter()->read($shop, $this->query());

        self::assertCount(0, $result->items);
        self::assertSame(0, $result->qualifyingCount);
    }

    public function test_cross_shop_failures_are_excluded_and_reads_are_bounded(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $foreign = $this->shop(['max_delivery_attempts' => 1]);
        $this->failedLeg($foreign);
        $this->failedLeg($shop);

        DB::enableQueryLog();
        $result = $this->adapter()->read($shop, new OwnerAttentionQuery(
            bucket: 'urgent_exceptions',
            perPage: 20,
            candidateLimit: 1,
        ));

        self::assertCount(1, $result->items);
        self::assertSame(1, $result->qualifyingCount);
        self::assertLessThanOrEqual(12, count(DB::getQueryLog()));
    }

    private function adapter(): UnownedLogisticsFailureAttentionAdapter
    {
        return app(UnownedLogisticsFailureAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(
            bucket: 'urgent_exceptions',
            perPage: 20,
            candidateLimit: 50,
        );
    }

    private function shop(array $settings = []): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        LogisticsSetting::create(array_replace(['shop_owner_id' => $shop->id], $settings));

        return $shop;
    }

    private function rider(ShopOwner $shop): RiderProfile
    {
        return RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
    }

    private function failedLeg(ShopOwner $shop): ShipmentLeg
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'needs_resolution',
            'failed_at' => now()->subDay(),
            'resolution_type' => null,
        ]);
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'attempt_number' => 1,
            'attempted_at' => now()->subDay(),
        ]);

        return $leg;
    }
}
