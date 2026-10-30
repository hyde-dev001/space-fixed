<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handoff_proofs', function (Blueprint $table) {
            $table->foreignId('replaces_proof_id')
                ->nullable()
                ->after('shipment_leg_id')
                ->constrained('handoff_proofs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('handoff_proofs', function (Blueprint $table) {
            $table->dropForeign(['replaces_proof_id']);
            $table->dropColumn('replaces_proof_id');
        });
    }
};
