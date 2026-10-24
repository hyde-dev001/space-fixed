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
        Schema::create('shipment_custody_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('shipment_leg_id')->constrained('shipment_legs')->restrictOnDelete();
            $table->foreignId('delivery_assignment_id')->nullable()->constrained('delivery_assignments')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('from_type', 16);
            $table->foreignId('from_rider_profile_id')->nullable()->constrained('rider_profiles')->restrictOnDelete();
            $table->string('to_type', 16);
            $table->foreignId('to_rider_profile_id')->nullable()->constrained('rider_profiles')->restrictOnDelete();
            $table->string('status', 16)->default('requested');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('release_proof_id')->nullable()->constrained('handoff_proofs')->restrictOnDelete();
            $table->foreignId('receipt_proof_id')->nullable()->constrained('handoff_proofs')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['shipment_leg_id', 'status']);
            $table->index(['shop_owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_custody_transfers');
    }
};
