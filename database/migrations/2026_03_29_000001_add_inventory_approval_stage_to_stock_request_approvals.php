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
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->foreignId('inventory_approved_by')
                ->nullable()
                ->after('approved_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('inventory_approved_date')
                ->nullable()
                ->after('inventory_approved_by');
            $table->text('inventory_approval_notes')
                ->nullable()
                ->after('approval_notes');

            $table->index('inventory_approved_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->dropIndex(['inventory_approved_date']);
            $table->dropConstrainedForeignId('inventory_approved_by');
            $table->dropColumn('inventory_approved_date');
            $table->dropColumn('inventory_approval_notes');
        });
    }
};
