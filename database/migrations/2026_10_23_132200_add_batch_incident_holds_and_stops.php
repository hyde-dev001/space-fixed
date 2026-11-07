<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_batches', function (Blueprint $table) {
            $table->foreignId('active_incident_id')->nullable()->constrained('delivery_incidents')->restrictOnDelete();
        });
        Schema::create('delivery_incident_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_incident_id')->constrained('delivery_incidents')->restrictOnDelete();
            $table->foreignId('shipment_leg_id')->constrained('shipment_legs')->restrictOnDelete();
            $table->unsignedSmallInteger('original_sequence')->nullable();
            $table->foreignId('original_assignment_id')->nullable()->constrained('delivery_assignments')->restrictOnDelete();
            $table->json('snapshot');
            $table->boolean('held')->default(true);
            $table->string('outcome')->nullable();
            $table->timestamps();
            $table->unique(['delivery_incident_id', 'shipment_leg_id'], 'incident_stop_unique');
            $table->index(['shipment_leg_id', 'held']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('delivery_incident_stops')->exists()) {
            throw new RuntimeException('Do not drop batch interruption evidence. Retain the additive schema.');
        }
        Schema::table('delivery_batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('active_incident_id'));
        Schema::dropIfExists('delivery_incident_stops');
    }
};
