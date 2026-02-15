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
        Schema::table('repair_services', function (Blueprint $table) {
            $table->text('finance_notes')->nullable()->after('rejection_reason');
            $table->foreignId('finance_reviewed_by')->nullable()->constrained('users')->onDelete('set null')->after('finance_notes');
            $table->timestamp('finance_reviewed_at')->nullable()->after('finance_reviewed_by');
            $table->foreignId('owner_reviewed_by')->nullable()->constrained('shop_owners')->onDelete('set null')->after('finance_reviewed_at');
            $table->timestamp('owner_reviewed_at')->nullable()->after('owner_reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_services', function (Blueprint $table) {
            $table->dropForeign(['finance_reviewed_by']);
            $table->dropForeign(['owner_reviewed_by']);
            $table->dropColumn([
                'finance_notes',
                'finance_reviewed_by',
                'finance_reviewed_at',
                'owner_reviewed_by',
                'owner_reviewed_at',
            ]);
        });
    }
};
