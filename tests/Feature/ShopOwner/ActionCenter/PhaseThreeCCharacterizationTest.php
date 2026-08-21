<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\Adapters\UnownedLogisticsFailureAttentionAdapter;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Services\OwnerActionCenter\OwnerAttentionAdapterRegistry;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PhaseThreeCCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_three_baseline_keeps_existing_adapter_families_and_does_not_register_waiting(): void
    {
        config([
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [],
            'owner_action_center.coverage' => [
                'refunds' => true,
                'expenses' => true,
                'purchase_requests' => true,
            ],
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage' => [
                'compliance' => true,
                'refunds' => true,
                'logistics' => true,
            ],
        ]);

        $registry = app(OwnerAttentionAdapterRegistry::class);

        $this->assertSame([
            'order_refunds',
            'repair_refunds',
            'expenses',
            'purchase_requests',
        ], array_map(
            static fn ($adapter): string => $adapter->adapterKey(),
            $registry->adaptersFor('needs_my_decision'),
        ));
        $this->assertSame([
            'compliance_documents',
            'failed_order_refunds',
            'failed_repair_refunds',
            'unowned_logistics_failures',
        ], array_map(
            static fn ($adapter): string => $adapter->adapterKey(),
            $registry->adaptersFor('urgent_exceptions'),
        ));
        $this->assertSame([], $registry->adaptersFor('waiting_on_others'));
    }

    public function test_waiting_baseline_is_no_enabled_and_absent_from_the_action_center_page(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        config([
            'owner_action_center.buckets.waiting_on_others.enabled' => false,
        ]);

        $result = app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others'),
        );

        $this->assertSame([], $result->items);
        $this->assertSame([], $result->enabledAdapterKeys);
        $this->assertSame(OwnerActionCenterDegradationStatus::NoEnabledAdapters, $result->degradationStatus);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/ActionCenter', false)
                ->has('bucketSummaries.needs_my_decision')
                ->has('bucketSummaries.urgent_exceptions')
                ->missing('bucketSummaries.waiting_on_others'));
    }

    public function test_unowned_logistics_exception_does_not_overlap_with_waiting_in_the_baseline(): void
    {
        $shop = $this->shop(['max_delivery_attempts' => 1]);
        $leg = $this->failedLeg($shop);

        $exception = app(UnownedLogisticsFailureAttentionAdapter::class)->read(
            $shop,
            new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'logistics'),
        );
        $waiting = app(OwnerActionCenterService::class)->queueForActionCenter(
            $shop,
            new OwnerAttentionQuery(bucket: 'waiting_on_others'),
        );

        $this->assertCount(1, $exception->items);
        $this->assertSame('logistics_failure:'.$leg->id.':unowned_delivery_failure', $exception->items[0]->attentionKey);
        $this->assertSame([], $waiting->items);
    }

    /** @param array<string, mixed> $settings */
    private function shop(array $settings): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        LogisticsSetting::create(array_replace(['shop_owner_id' => $shop->id], $settings));

        return $shop;
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
