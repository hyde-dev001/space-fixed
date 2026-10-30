<?php

use App\Models\Logistics\DeliveryBatch;
use App\Support\Logistics\BatchStopSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_batches', fn (Blueprint $table) => $table->json('stop_snapshot')->nullable());

        DeliveryBatch::query()->with('legs.shipment')->chunkById(100, function ($batches) {
            foreach ($batches as $batch) {
                $snapshot = $batch->cancelled_stops
                    ? BatchStopSnapshot::normalize($batch->cancelled_stops)
                    : BatchStopSnapshot::fromLegs($batch->legs);

                DeliveryBatch::withoutTimestamps(fn () => $batch->updateQuietly(['stop_snapshot' => $snapshot]));
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_batches', fn (Blueprint $table) => $table->dropColumn('stop_snapshot'));
    }
};
