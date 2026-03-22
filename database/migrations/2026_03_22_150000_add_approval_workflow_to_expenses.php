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
        Schema::table('finance_expenses', function (Blueprint $table) {
            // Link to approval records with 4-step workflow
            $table->unsignedBigInteger('approval_id')->nullable()->after('id');
            $table->foreign('approval_id')->references('id')->on('approvals')->onDelete('set null');
            
            // Keep track of which approval level created the expense state
            $table->integer('current_approval_level')->nullable()->after('approval_id');
            
            // Status values will be: draft, submitted, pending_shop_owner, pending_finance_final, approved, rejected
            $table->index('approval_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropForeign(['approval_id']);
            $table->dropColumn(['approval_id', 'current_approval_level']);
        });
    }
};
