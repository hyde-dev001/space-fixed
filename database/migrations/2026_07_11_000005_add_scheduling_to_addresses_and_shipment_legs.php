<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->index(['latitude', 'longitude']);
        });

        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->date('scheduled_delivery_date')->nullable();
            $table->string('delivery_window')->nullable();
            $table->string('schedule_status')->nullable();
            $table->text('schedule_override_reason')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->timestamp('estimated_at')->nullable();
            $table->index(['scheduled_delivery_date', 'delivery_window', 'schedule_status'], 'shipment_legs_schedule_index');
        });

    }

    public function down(): void
    {
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->dropIndex('shipment_legs_schedule_index');
            $table->dropColumn(['scheduled_delivery_date', 'delivery_window', 'schedule_status', 'schedule_override_reason', 'distance_km', 'estimated_at']);
        });
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropColumn(['latitude', 'longitude', 'delivery_instructions']);
        });
    }
};
