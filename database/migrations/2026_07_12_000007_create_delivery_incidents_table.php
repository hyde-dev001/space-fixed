<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('shipment_leg_id')->constrained('shipment_legs')->cascadeOnDelete();
            $table->foreignId('reporting_rider_profile_id')->constrained('rider_profiles');
            $table->string('type');
            $table->string('status')->default('reported');
            $table->json('photo_paths')->nullable();
            $table->text('notes');
            $table->text('resolution')->nullable();
            $table->string('responsible_party')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['shop_owner_id', 'status', 'type']);
        });
    }

    public function down(): void { Schema::dropIfExists('delivery_incidents'); }
};
