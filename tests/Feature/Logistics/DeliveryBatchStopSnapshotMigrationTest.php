<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Support\Logistics\BatchStopSnapshot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryBatchStopSnapshotMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        return ['--step' => true];
    }

    public function test_migration_backfills_ordered_normalized_snapshots_from_cancelled_stops_or_live_legs(): void
    {
        $this->assertTrue(class_exists(BatchStopSnapshot::class));

        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $statuses = ['draft', 'offered', 'accepted', 'in_progress', 'completed', 'cancelled'];
        $batches = collect($statuses)->mapWithKeys(function (string $status, int $index) use ($shop) {
            $batch = DeliveryBatch::factory()->create([
                'shop_owner_id' => $shop->id,
                'status' => $status,
                'updated_at' => '2026-07-01 12:00:00',
                'stop_snapshot' => null,
                'cancelled_stops' => $status === 'cancelled'
                    ? [$this->stop(9002, 2, 9992), $this->stop(9001, 1, 9991)]
                    : ($status === 'draft' ? [] : null),
            ]);
            $shipment = Shipment::factory()->create([
                'shop_owner_id' => $shop->id,
                'source_type' => 'order',
                'source_id' => 100 + $index,
            ]);
            foreach ([2, 1] as $stopSequence) {
                ShipmentLeg::factory()->create([
                    'shipment_id' => $shipment->id,
                    'delivery_batch_id' => $batch->id,
                    'sequence' => 6 + $stopSequence,
                    'status' => 'assigned',
                    'origin_snapshot' => ['name' => 'Warehouse'],
                    'destination_snapshot' => ['name' => "Customer {$index}"],
                    'scheduled_delivery_date' => '2026-07-20',
                    'delivery_window' => 'morning',
                    'schedule_status' => 'scheduled',
                    'stop_sequence' => $stopSequence,
                    'urgent_at' => '2026-07-17 08:30:00',
                ]);
            }

            return [$status => $batch->id];
        });

        Schema::table('delivery_batches', fn (Blueprint $table) => $table->dropColumn('stop_snapshot'));
        $migration = require database_path('migrations/2026_07_17_000002_add_stop_snapshot_to_delivery_batches.php');
        $migration->up();

        $this->assertSame('2026-07-01 12:00:00', DeliveryBatch::findOrFail($batches['completed'])->getRawOriginal('updated_at'));

        foreach ($statuses as $index => $status) {
            $snapshot = DeliveryBatch::findOrFail($batches[$status])->stop_snapshot;
            $this->assertNotEmpty($snapshot, $status);
            foreach ($snapshot as $stop) {
                $this->assertSame($this->keys(), array_keys($stop), $status);
                $this->assertSame(['id', 'source_type', 'source_id'], array_keys($stop['shipment']), $status);
            }
            $this->assertSame([1, 2], array_column($snapshot, 'stop_sequence'), $status);
            $this->assertSame($status === 'cancelled' ? 9991 : 100 + $index, $snapshot[0]['shipment']['source_id'], $status);
            $this->assertSame('assigned', $snapshot[0]['status']);
            $this->assertSame('2026-07-20', $snapshot[0]['scheduled_delivery_date']);
            $this->assertSame('2026-07-17T08:30:00+00:00', $snapshot[0]['urgent_at']);
        }

        $cancelled = DeliveryBatch::findOrFail($batches['cancelled'])->stop_snapshot;
        $this->assertSame([9001, 9002], array_column($cancelled, 'id'));
        $this->assertNull($cancelled[1]['scheduled_delivery_date']);
        $this->assertNull($cancelled[1]['urgent_at']);
    }

    public function test_down_drops_only_stop_snapshot(): void
    {
        $migration = require database_path('migrations/2026_07_17_000002_add_stop_snapshot_to_delivery_batches.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('delivery_batches', 'stop_snapshot'));
        $this->assertTrue(Schema::hasColumns('delivery_batches', ['status', 'cancellation_reason', 'cancelled_stops']));
        $this->assertTrue(Schema::hasColumns('shipment_legs', ['delivery_batch_id', 'stop_sequence']));

        $migration->up();
    }

    private function stop(int $id, int $stopSequence, int $sourceId): array
    {
        $stop = [
            'id' => $id,
            'sequence' => 7,
            'leg_type' => 'outbound',
            'status' => 'assigned',
            'origin_snapshot' => ['name' => 'Archived warehouse'],
            'destination_snapshot' => ['name' => 'Archived customer'],
            'scheduled_delivery_date' => '2026-07-20T00:00:00.000000Z',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'stop_sequence' => $stopSequence,
            'urgent_at' => '2026-07-17T08:30:00.000000Z',
            'shipment' => ['id' => 5000 + $id, 'source_type' => 'order', 'source_id' => $sourceId, 'extra' => 'discard'],
            'extra' => 'discard',
        ];

        if ($stopSequence === 2) {
            unset($stop['scheduled_delivery_date'], $stop['urgent_at']);
        }

        return $stop;
    }

    private function keys(): array
    {
        return [
            'id', 'sequence', 'leg_type', 'status', 'origin_snapshot', 'destination_snapshot',
            'scheduled_delivery_date', 'delivery_window', 'schedule_status', 'stop_sequence',
            'urgent_at', 'shipment',
        ];
    }
}
