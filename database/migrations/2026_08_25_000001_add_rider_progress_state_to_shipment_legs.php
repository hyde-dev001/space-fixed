<?php

use App\Enums\Logistics\RiderProgressState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->string('rider_progress_state')
                ->default(RiderProgressState::ACTIVE->value)
                ->after('status');
            $table->index(
                ['rider_progress_state', 'delivery_batch_id', 'stop_sequence'],
                'shipment_legs_rider_progress_batch_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->dropIndex('shipment_legs_rider_progress_batch_index');
            $table->dropColumn('rider_progress_state');
        });
    }
};
