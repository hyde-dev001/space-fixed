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
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->string('custody_state', 16)->default('unknown');
            $table->foreignId('custody_rider_profile_id')->nullable()->constrained('rider_profiles')->restrictOnDelete();
            $table->timestamp('custody_confirmed_at')->nullable();
        });
        Schema::table('handoff_proofs', function (Blueprint $table) {
            $table->foreignId('delivery_assignment_id')->nullable()->constrained('delivery_assignments')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('handoff_proofs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_assignment_id');
        });
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custody_rider_profile_id');
            $table->dropColumn(['custody_state', 'custody_confirmed_at']);
        });
    }
};
