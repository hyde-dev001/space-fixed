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
        Schema::table('pos_refunds', function (Blueprint $table): void {
            $table->string('recovery_status', 32)->nullable()->after('failed_at');
            $table->string('recovery_responsible_party', 32)->nullable()->after('recovery_status');
            $table->unsignedInteger('recovery_attempt_count')->default(0)->after('recovery_responsible_party');
            $table->timestamp('recovery_last_attempted_at')->nullable()->after('recovery_attempt_count');
            $table->timestamp('recovery_resolved_at')->nullable()->after('recovery_last_attempted_at');
            $table->string('recovery_resolved_by_type', 32)->nullable()->after('recovery_resolved_at');
            $table->unsignedBigInteger('recovery_resolved_by_id')->nullable()->after('recovery_resolved_by_type');
            $table->string('recovery_resolution_outcome', 40)->nullable()->after('recovery_resolved_by_id');
            $table->text('recovery_resolution_reason')->nullable()->after('recovery_resolution_outcome');
            $table->foreignId('replacement_refund_id')
                ->nullable()
                ->after('recovery_resolution_reason')
                ->constrained('pos_refunds')
                ->nullOnDelete();
            $table->index(
                ['shop_owner_id', 'recovery_status', 'recovery_responsible_party'],
                'pos_refunds_recovery_scope_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table): void {
            $table->dropIndex('pos_refunds_recovery_scope_index');
            $table->dropForeign(['replacement_refund_id']);
            $table->dropColumn([
                'recovery_status',
                'recovery_responsible_party',
                'recovery_attempt_count',
                'recovery_last_attempted_at',
                'recovery_resolved_at',
                'recovery_resolved_by_type',
                'recovery_resolved_by_id',
                'recovery_resolution_outcome',
                'recovery_resolution_reason',
                'replacement_refund_id',
            ]);
        });
    }
};
