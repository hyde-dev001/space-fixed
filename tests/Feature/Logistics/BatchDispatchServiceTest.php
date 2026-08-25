<?php

namespace Tests\Feature\Logistics;

use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\BatchDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_unscheduled_leg_can_be_scheduled(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], [
            'operating_days' => [1, 2, 3, 4, 5, 6, 7],
            'blackout_dates' => [],
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order'])->id,
            'status' => 'assigned',
            'schedule_status' => 'unscheduled',
            'delivery_batch_id' => null,
        ]);
        $date = now()->addDay()->toDateString();

        app(BatchDispatchService::class)->schedule($shop, $date, 'morning', [$leg->id]);

        $leg->refresh();
        $this->assertSame($date, $leg->scheduled_delivery_date->toDateString());
        $this->assertSame('morning', $leg->delivery_window);
        $this->assertSame('scheduled', $leg->schedule_status);
    }

    public function test_schedule_rejects_ineligible_legs(): void
    {
        $shop = ShopOwner::factory()->create();
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], [
            'operating_days' => [1, 2, 3, 4, 5, 6, 7],
            'blackout_dates' => [],
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        $legs = [
            'foreign tenant' => ShipmentLeg::factory()->create([
                'shipment_id' => Shipment::factory()->create()->id,
                'status' => 'pending',
                'schedule_status' => 'unscheduled',
            ]),
            'batched' => ShipmentLeg::factory()->create([
                'shipment_id' => $shipment->id,
                'status' => 'pending',
                'schedule_status' => 'unscheduled',
                'delivery_batch_id' => $batch->id,
            ]),
            'already scheduled' => ShipmentLeg::factory()->create([
                'shipment_id' => $shipment->id,
                'status' => 'pending',
                'schedule_status' => 'scheduled',
            ]),
            'invalid status' => ShipmentLeg::factory()->create([
                'shipment_id' => $shipment->id,
                'status' => 'picked_up',
                'schedule_status' => 'unscheduled',
            ]),
        ];

        foreach ($legs as $case => $leg) {
            try {
                app(BatchDispatchService::class)->schedule($shop, now()->addDay()->toDateString(), 'morning', [$leg->id]);
                $this->fail("{$case} leg was scheduled.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('legs', $exception->errors(), $case);
            }
        }
    }

    public function test_create_draft_rejects_a_shop_closed_delivery_date(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'operating_days' => [1, 2, 3, 4, 5],
            'blackout_dates' => ['2026-07-17'],
        ]);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order'])->id,
            'scheduled_delivery_date' => '2026-07-17',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Choose an operating day that is not a blackout date.');

        app(BatchDispatchService::class)->createDraft($shop, '2026-07-17', 'morning', $legs->pluck('id')->all());
    }

    public function test_draft_offer_accept_and_start_preserve_individual_leg_state(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);

        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());
        $this->assertSame([1, 2], $batch->legs->pluck('stop_sequence')->all());
        $batch = $service->offer($batch, $rider, $shop);
        $this->assertSame('offered', $batch->status);
        $this->assertCount(2, $batch->legs->flatMap->assignments);
        $this->assertSame('accepted', $service->accept($batch, $rider)->status);
        $started = $service->start($batch->fresh(), $rider);
        $this->assertSame('in_progress', $started->status);
        $this->assertSame(['assigned'], $started->legs->pluck('status.value')->unique()->values()->all());
    }

    public function test_batch_offer_rejects_an_unlinked_employee_rider(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $rider = RiderProfile::query()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'name' => 'Unlinked employee',
            'availability_status' => 'available',
            'active' => true,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());

        try {
            $service->offer($batch, $rider, $shop);
            $this->fail('An unlinked employee rider was offered work.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rider_profile_id', $exception->errors());
        }

        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertDatabaseCount('delivery_assignments', 0);
    }

    public function test_rejection_returns_batch_to_draft_and_cancellation_returns_legs_to_pool(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order'])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);
        $batch = $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop);

        $this->expectException(ValidationException::class);
        $service->reject($batch, $rider, '');
    }

    public function test_rejection_reason_and_cancel_are_recorded(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => 72,
        ]);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
            'destination_snapshot' => ['name' => 'Miguel Dela Rosa', 'address' => 'Bacoor, Cavite'],
        ]);
        $service = app(BatchDispatchService::class);
        $batch = $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop);

        $rejected = $service->reject($batch, $rider, 'Vehicle unavailable');
        $this->assertSame('draft', $rejected->status);
        $this->assertNull($rejected->rider_profile_id);
        $this->assertSame('Vehicle unavailable', $rejected->rejection_reason);
        $this->assertNotNull($rejected->rejected_at);

        $reoffered = $service->offer($rejected, $rider, $shop);
        $this->assertNull($reoffered->rejection_reason);
        $this->assertNull($reoffered->rejected_at);

        $rejected = $service->reject($reoffered, $rider, 'Still unavailable');
        $cancelled = $service->cancel($rejected, 'No longer required');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('No longer required', $cancelled->cancellation_reason);
        $this->assertSame(72, $cancelled->cancelled_stops[0]['shipment']['source_id']);
        $this->assertSame('Miguel Dela Rosa', $cancelled->cancelled_stops[0]['destination_snapshot']['name']);
        $this->assertTrue($legs->every(fn (ShipmentLeg $leg) => $leg->fresh()->delivery_batch_id === null));
    }

    public function test_cancelled_batch_can_be_restored_to_draft(): void
    {
        [$shop, $legs, $service] = $this->draftFixture(2);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());
        $cancelled = $service->cancel($batch, 'Route changed');

        $restored = $service->restore($cancelled);

        $this->assertSame('draft', $restored->status);
        $this->assertSame($legs->pluck('id')->all(), $restored->legs->pluck('id')->all());
        $this->assertSame([1, 2], $restored->legs->pluck('stop_sequence')->all());
        $this->assertNull($restored->cancellation_reason);
        $this->assertNull($restored->cancelled_stops);
    }

    public function test_draft_stops_can_be_reordered_removed_and_marked_urgent(): void
    {
        [$shop, $legs, $service] = $this->draftFixture(2);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());

        $this->assertSame($legs->pluck('id')->all(), collect($batch->stop_snapshot)->pluck('id')->all());

        $updated = $service->replaceStops($batch, $legs->pluck('id')->reverse()->values()->all());
        $this->assertSame($legs->pluck('id')->reverse()->values()->all(), $updated->legs->pluck('id')->all());
        $this->assertSame($legs->pluck('id')->reverse()->values()->all(), collect($updated->stop_snapshot)->pluck('id')->all());
        $service->markUrgent($legs->first(), true);
        $this->assertNotNull($legs->first()->fresh()->urgent_at);
        $this->assertNotNull(collect($updated->fresh()->stop_snapshot)->firstWhere('id', $legs->first()->id)['urgent_at']);
        $service->removeStop($updated, $legs->first());
        $this->assertSame(1, $updated->fresh()->assigned_stop_count);
        $this->assertSame([$legs->last()->id], collect($updated->fresh()->stop_snapshot)->pluck('id')->all());
    }

    public function test_draft_urgency_is_snapshotted_before_immediate_cancellation(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());

        $service->markUrgent($legs->first(), true);
        $cancelled = $service->cancel($batch, 'Route changed');

        $this->assertNotNull($cancelled->stop_snapshot[0]['urgent_at']);
    }

    public function test_unattached_urgency_retries_when_the_leg_is_attached_during_the_update(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $leg = $legs->first();
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'draft']);
        $attached = false;
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        try {
            $connection->listen(function ($query) use (&$attached, $leg, $batch) {
                if (! $attached && str_starts_with(strtolower(ltrim($query->sql)), 'select')
                    && str_contains($query->sql, 'delivery_batch_id') && str_contains($query->sql, 'shipment_legs')) {
                    $attached = true;
                    ShipmentLeg::query()->whereKey($leg->id)->update([
                        'delivery_batch_id' => $batch->id, 'stop_sequence' => 1,
                    ]);
                }
            });
            $updated = $service->markUrgent($leg, true);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->assertTrue($attached);
        $this->assertNotNull($updated->urgent_at);
        $this->assertNotNull($batch->fresh()->stop_snapshot[0]['urgent_at']);
    }

    public function test_clearing_already_clear_urgency_on_an_unattached_nonterminal_leg_succeeds(): void
    {
        [, $legs, $service] = $this->draftFixture();
        $leg = $legs->first();
        $this->assertNull($leg->urgent_at);
        DB::statement('CREATE TRIGGER ignore_unchanged_urgency BEFORE UPDATE OF urgent_at ON shipment_legs
            WHEN NEW.urgent_at IS OLD.urgent_at BEGIN SELECT RAISE(IGNORE); END');
        try {
            $updated = $service->markUrgent($leg, false);
        } finally {
            DB::statement('DROP TRIGGER ignore_unchanged_urgency');
        }

        $this->assertNull($updated->delivery_batch_id);
        $this->assertNull($updated->urgent_at);
    }

    public function test_offer_performs_final_refresh_and_later_leg_changes_and_cancellation_do_not_rewrite_snapshot(): void
    {
        [$shop, $legs, $service] = $this->draftFixture(2);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());
        $legs->first()->update(['destination_snapshot' => ['name' => 'Final offer address']]);

        $offered = $service->offer($batch, $rider, $shop);
        $frozen = $offered->stop_snapshot;
        $this->assertSame('Final offer address', $frozen[0]['destination_snapshot']['name']);

        $legs->first()->update(['status' => 'in_transit', 'delivery_batch_id' => null, 'destination_snapshot' => ['name' => 'Live change']]);
        $cancelled = $service->cancel($offered, 'Route changed');

        $this->assertSame($frozen, $cancelled->stop_snapshot);
    }

    public function test_reject_then_draft_edit_then_reoffer_intentionally_refreshes_snapshot(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $offered = $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop);
        $this->assertNull($offered->stop_snapshot[0]['urgent_at']);

        $rejected = $service->reject($offered, $rider, 'Unavailable');
        $service->markUrgent($legs->first(), true);
        $reoffered = $service->offer($rejected, $rider, $shop);

        $this->assertNotNull($reoffered->stop_snapshot[0]['urgent_at']);
    }

    public function test_restore_prefers_non_empty_snapshot_and_refreshes_the_draft_snapshot(): void
    {
        [$shop, $legs, $service] = $this->draftFixture(2);
        $cancelled = $service->cancel(
            $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()),
            'Route changed'
        );
        $preferred = array_reverse($cancelled->stop_snapshot);
        $cancelled->update(['stop_snapshot' => $preferred, 'cancelled_stops' => [$cancelled->cancelled_stops[0]]]);

        $restored = $service->restore($cancelled);

        $this->assertSame($legs->pluck('id')->reverse()->values()->all(), $restored->legs->pluck('id')->all());
        $this->assertSame($legs->pluck('id')->reverse()->values()->all(), collect($restored->stop_snapshot)->pluck('id')->all());
    }

    public function test_restore_falls_back_to_cancelled_stops_for_null_or_empty_snapshot(): void
    {
        foreach ([null, []] as $snapshot) {
            [$shop, $legs, $service] = $this->draftFixture(2);
            $cancelled = $service->cancel(
                $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()),
                'Route changed'
            );
            $cancelled->update(['stop_snapshot' => $snapshot]);

            $restored = $service->restore($cancelled);

            $this->assertSame($legs->pluck('id')->all(), $restored->legs->pluck('id')->all());
            $this->assertSame($legs->pluck('id')->all(), collect($restored->stop_snapshot)->pluck('id')->all());
        }
    }

    public function test_restore_validates_only_the_preferred_snapshot_and_remains_all_or_nothing_on_conflict(): void
    {
        [$shop, $legs, $service] = $this->draftFixture(2);
        $cancelled = $service->cancel(
            $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()),
            'Route changed'
        );
        $otherBatch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        $legs->first()->update(['delivery_batch_id' => $otherBatch->id]);
        $cancelled->update(['cancelled_stops' => [$cancelled->cancelled_stops[1]]]);

        try {
            $service->restore($cancelled);
            $this->fail('Restore fell through to cancelled stops after the preferred snapshot conflicted.');
        } catch (ValidationException) {
            $this->assertSame('cancelled', $cancelled->fresh()->status);
            $this->assertSame($otherBatch->id, $legs->first()->fresh()->delivery_batch_id);
            $this->assertNull($legs->last()->fresh()->delivery_batch_id);
        }
    }

    public function test_restore_rejects_malformed_preferred_ids_without_fallback_or_partial_changes(): void
    {
        foreach ([[['id' => null]], [['id' => 0]], [['id' => -1]], [['id' => '1']], null] as $malformed) {
            [$shop, $legs, $service] = $this->draftFixture(2);
            $cancelled = $service->cancel(
                $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()),
                'Route changed'
            );
            $malformed ??= [['id' => $legs->first()->id], ['id' => $legs->first()->id]];
            $cancelled->update(['stop_snapshot' => $malformed]);

            try {
                $service->restore($cancelled);
                $this->fail('Malformed preferred stop history was restored or fell back to cancelled stops.');
            } catch (ValidationException) {
                $this->assertSame('cancelled', $cancelled->fresh()->status);
                $this->assertNull($legs->first()->fresh()->delivery_batch_id);
                $this->assertNull($legs->last()->fresh()->delivery_batch_id);
            }
        }
    }

    public function test_terminal_stops_cannot_be_marked_urgent(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();

        foreach (['delivered', 'cancelled'] as $status) {
            $leg = ShipmentLeg::factory()->create([
                'shipment_id' => $legs->first()->shipment_id,
                'status' => $status,
            ]);
            $this->assertSame($status, $leg->status->value);

            try {
                $service->markUrgent($leg, true);
                $this->fail("{$status} leg accepted an urgency change.");
            } catch (ValidationException) {
                $this->assertNull($leg->fresh()->urgent_at);
            }
        }
    }

    public function test_accept_and_start_are_idempotent_but_started_batch_cannot_be_cancelled(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $batch = $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop);

        $accepted = $service->accept($batch, $rider);
        $this->assertSame('accepted', $service->accept($accepted, $rider)->status);
        $started = $service->start($accepted->fresh(), $rider);
        $this->assertSame('in_progress', $service->start($started, $rider)->status);
        $this->assertSame(2, DeliveryEvent::whereIn('event_type', ['batch_accepted', 'batch_started'])->count());
        $this->assertDatabaseHas('delivery_events', ['event_type' => 'batch_accepted', 'visibility' => 'internal']);
        $this->assertDatabaseHas('delivery_events', ['event_type' => 'batch_started', 'visibility' => 'internal']);

        $this->expectException(ValidationException::class);
        $service->cancel($started, 'Unsafe cancellation');
    }

    public function test_repeating_batch_rejection_returns_the_original_response_without_duplicate_events(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        $batch = $service->offer(
            $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()),
            $rider,
            $shop,
        );

        $rejected = $service->reject($batch, $rider, 'Schedule conflict');
        $replayed = $service->reject($rejected->fresh(), $rider, 'Schedule conflict');

        $this->assertSame($rejected->id, $replayed->id);
        $this->assertSame('draft', $replayed->status);
        $this->assertSame(1, DeliveryEvent::where('event_type', 'batch_rejected')->count());
    }

    public function test_rider_cannot_start_a_batch_while_a_standalone_delivery_is_active(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = $service->accept(
            $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop),
            $rider,
        );
        $activeLeg = ShipmentLeg::factory()->create(['status' => 'in_transit', 'delivery_batch_id' => null]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $activeLeg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        try {
            $service->start($batch, $rider);
            $this->fail('The rider started a batch while a standalone delivery was active.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_work', $exception->errors());
        }

        $this->assertSame('accepted', $batch->fresh()->status);
    }

    public function test_rider_cannot_start_a_second_batch(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $otherBatch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $rider->id,
            'status' => 'in_progress',
        ]);
        $otherLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => $otherBatch->id,
            'stop_sequence' => 1,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $otherLeg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $batch = $service->accept(
            $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop),
            $rider,
        );

        try {
            $service->start($batch, $rider);
            $this->fail('The rider started a second batch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_work', $exception->errors());
        }

        $this->assertSame('accepted', $batch->fresh()->status);
    }

    public function test_repeating_start_for_the_same_batch_remains_idempotent(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = $service->accept(
            $service->offer($service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all()), $rider, $shop),
            $rider,
        );

        $started = $service->start($batch, $rider);

        $this->assertSame('in_progress', $service->start($started, $rider)->status);
        $this->assertSame(1, DeliveryEvent::where('event_type', 'batch_started')->count());
    }

    public function test_offer_rejects_unavailable_or_off_schedule_rider(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'busy',
            'work_days' => [1],
        ]);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());

        $this->expectException(ValidationException::class);
        $service->offer($batch, $rider, $shop);
    }

    public function test_offer_rejects_employee_rider_on_approved_hr_leave(): void
    {
        [$shop, $legs, $service] = $this->draftFixture();
        $user = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'email' => 'rider-on-leave@example.com',
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $shop->id,
            'email' => $user->email,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'leave_type' => 'vacation',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-15',
            'reason' => 'Approved leave',
            'status' => 'approved',
        ]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $user->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());

        try {
            $service->offer($batch, $rider, $shop);
            $this->fail('A delivery offer was sent to a rider on approved leave.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rider_profile_id', $exception->errors());
        }

        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertDatabaseMissing('delivery_assignments', ['rider_profile_id' => $rider->id]);
    }

    public function test_offer_enforces_cumulative_daily_capacity_and_audits_override(): void
    {
        [$shop, $legs, $service] = $this->draftFixture(2);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['daily_rider_capacity' => 6]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available',
            'daily_capacity' => null,
        ]);
        DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
            'delivery_date' => '2026-07-15', 'delivery_window' => 'afternoon',
            'status' => 'in_progress', 'assigned_stop_count' => 5,
        ]);
        foreach (['draft', 'cancelled'] as $status) {
            DeliveryBatch::factory()->create([
                'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
                'delivery_date' => '2026-07-15', 'status' => $status, 'assigned_stop_count' => 20,
            ]);
        }
        DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
            'delivery_date' => '2026-07-16', 'status' => 'accepted', 'assigned_stop_count' => 20,
        ]);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());

        try {
            $service->offer($batch, $rider, $shop);
            $this->fail('Over-capacity offer was accepted without an override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity_override_reason', $exception->errors());
        }

        $offered = $service->offer($batch, $rider, $shop, 'Operational priority');
        $event = DeliveryEvent::where('event_type', 'batch_offered')->latest('id')->firstOrFail();

        $this->assertSame('offered', $offered->status);
        $this->assertSame(5, $event->metadata['existing_stop_count']);
        $this->assertSame(2, $event->metadata['offered_stop_count']);
        $this->assertSame(7, $event->metadata['projected_stop_count']);
        $this->assertSame(6, $event->metadata['daily_capacity']);
        $this->assertSame('Operational priority', $event->metadata['capacity_override_reason']);
    }

    public function test_batch_creation_and_replacement_reject_single_or_mixed_module_stops_without_mutation(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $retailShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $repairShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'repair_request']);
        $retailLegs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $retailShipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $repairLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $repairShipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);

        foreach ([
            [$retailLegs->first()->id],
            [$retailLegs->first()->id, $repairLeg->id],
        ] as $ids) {
            try {
                $service->createDraft($shop, '2026-07-15', 'morning', $ids);
                $this->fail('Invalid batch composition was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('legs', $exception->errors());
                $this->assertSame(0, DeliveryBatch::query()->count());
                $this->assertTrue(
                    ShipmentLeg::query()->whereIn('id', $ids)->get()->every(
                        fn (ShipmentLeg $leg) => $leg->delivery_batch_id === null
                    )
                );
            }
        }

        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $retailLegs->pluck('id')->all());
        try {
            $service->replaceStops($batch, [$retailLegs->first()->id]);
            $this->fail('A full replacement reduced a batch below two stops.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legs', $exception->errors());
            $this->assertSame(2, $batch->fresh()->assigned_stop_count);
        }

        $reduced = $service->removeStop($batch, $retailLegs->first());
        $this->assertSame(1, $reduced?->assigned_stop_count);
    }

    public function test_shop_module_rules_reject_disallowed_batch_and_schedule_before_settings_or_leg_changes(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'repair']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);

        try {
            $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());
            $this->fail('Repair-only shop accepted a Retail batch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legs', $exception->errors());
            $this->assertDatabaseCount('logistics_settings', 0);
            $this->assertDatabaseCount('delivery_batches', 0);
        }

        $unscheduled = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => null,
            'delivery_window' => null,
            'schedule_status' => 'unscheduled',
            'status' => 'pending',
        ]);
        try {
            $service->schedule($shop, '2026-07-15', 'morning', [$unscheduled->id]);
            $this->fail('Repair-only shop scheduled a Retail delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legs', $exception->errors());
            $this->assertDatabaseCount('logistics_settings', 0);
            $this->assertNull($unscheduled->fresh()->scheduled_delivery_date);
        }
    }

    private function draftFixture(int $count = 2): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $legs = ShipmentLeg::factory()->count($count)->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);

        return [$shop, $legs, app(BatchDispatchService::class)];
    }
}
