<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->unique()->constrained('shop_owners')->cascadeOnDelete();
            $table->json('operating_days')->default('[1,2,3,4,5,6]');
            $table->time('cutoff_time')->default('15:00');
            $table->json('blackout_dates')->default('[]');
            $table->unsignedSmallInteger('lead_time_days')->default(1);
            $table->time('morning_start')->default('08:00');
            $table->time('morning_end')->default('12:00');
            $table->time('afternoon_start')->default('13:00');
            $table->time('afternoon_end')->default('18:00');
            $table->decimal('coverage_radius_km', 8, 2)->default(20);
            $table->unsignedSmallInteger('daily_rider_capacity')->default(20);
            $table->unsignedSmallInteger('max_delivery_attempts')->default(2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_settings');
    }
};
