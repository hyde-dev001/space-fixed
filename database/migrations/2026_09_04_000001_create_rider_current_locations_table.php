<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_current_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_leg_id')->unique()->constrained('shipment_legs')->cascadeOnDelete();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->cascadeOnDelete();
            $table->foreignId('delivery_assignment_id')->constrained('delivery_assignments')->cascadeOnDelete();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 3)->nullable();
            $table->decimal('heading_deg', 6, 2)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['rider_profile_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_current_locations');
    }
};
