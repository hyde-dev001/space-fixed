<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_current_locations', function (Blueprint $table): void {
            $table->json('route_geometry')->nullable();
            $table->decimal('route_distance_m', 12, 2)->nullable();
            $table->unsignedInteger('route_duration_s')->nullable();
            $table->string('route_source', 20)->nullable();
            $table->unsignedInteger('route_version')->default(0);
            $table->timestamp('route_updated_at')->nullable();
            $table->unsignedTinyInteger('route_off_route_samples')->default(0);
            $table->string('route_off_route_state', 20)->default('on_route');
            $table->timestamp('route_cooldown_until')->nullable();
            $table->decimal('route_target_latitude', 10, 8)->nullable();
            $table->decimal('route_target_longitude', 11, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rider_current_locations', function (Blueprint $table): void {
            $table->dropColumn([
                'route_geometry',
                'route_distance_m',
                'route_duration_s',
                'route_source',
                'route_version',
                'route_updated_at',
                'route_off_route_samples',
                'route_off_route_state',
                'route_cooldown_until',
                'route_target_latitude',
                'route_target_longitude',
            ]);
        });
    }
};
