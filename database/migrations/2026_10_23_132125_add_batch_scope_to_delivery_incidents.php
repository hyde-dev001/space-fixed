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
        Schema::table('delivery_incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_leg_id')->nullable()->change();
            $table->foreignId('delivery_batch_id')->nullable()->constrained('delivery_batches')->restrictOnDelete();
            $table->foreignId('recovery_batch_id')->nullable()->constrained('delivery_batches')->restrictOnDelete();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_facts_hash', 64)->nullable();
            $table->uuid('decision_key')->nullable()->unique();
            $table->string('decision_facts_hash', 64)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->index(['delivery_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('delivery_incidents')->whereNotNull('delivery_batch_id')->exists()) {
            throw new RuntimeException('Retain batch incident history and holds. A forward migration is required.');
        }
        Schema::table('delivery_incidents', function (Blueprint $table) {
            $table->dropForeign(['delivery_batch_id']);
            $table->dropIndex(['delivery_batch_id', 'status']);
            $table->dropColumn('delivery_batch_id');
            $table->dropConstrainedForeignId('recovery_batch_id');
            $table->dropConstrainedForeignId('decided_by_user_id');
            $table->dropUnique(['request_key']);
            $table->dropUnique(['decision_key']);
            $table->dropColumn(['request_key', 'request_facts_hash', 'decision_key', 'decision_facts_hash', 'decided_at', 'revision']);
            $table->unsignedBigInteger('shipment_leg_id')->nullable(false)->change();
        });
    }
};
