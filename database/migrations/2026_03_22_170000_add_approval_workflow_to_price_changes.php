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
        Schema::table('price_change_requests', function (Blueprint $table) {
            // Link to centralized approval workflow
            $table->unsignedBigInteger('approval_id')->nullable()->after('id');
            $table->foreign('approval_id')->references('id')->on('approvals')->onDelete('set null');
            
            // Track approval level in 4-step workflow
            $table->integer('current_approval_level')->nullable()->after('approval_id');
            
            // Distinguish workflows
            $table->string('approval_workflow_version')->default('legacy')->after('current_approval_level');
            
            // Index for quick lookups
            $table->index('approval_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_change_requests', function (Blueprint $table) {
            $table->dropForeign(['approval_id']);
            $table->dropColumn(['approval_id', 'current_approval_level', 'approval_workflow_version']);
        });
    }
};
