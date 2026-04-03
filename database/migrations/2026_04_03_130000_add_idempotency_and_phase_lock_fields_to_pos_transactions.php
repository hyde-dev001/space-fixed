<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('transaction_no');
            $table->string('phase_lock_key', 120)->nullable()->after('idempotency_key');
            $table->index(['module_type', 'module_reference_id', 'due_type'], 'idx_pos_phase_lookup');
            $table->unique(['phase_lock_key'], 'uq_pos_phase_lock_key');
            $table->unique(['module_type', 'module_reference_id', 'due_type', 'idempotency_key'], 'uq_pos_idempotency_scope');
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropUnique('uq_pos_idempotency_scope');
            $table->dropUnique('uq_pos_phase_lock_key');
            $table->dropIndex('idx_pos_phase_lookup');
            $table->dropColumn(['idempotency_key', 'phase_lock_key']);
        });
    }
};
