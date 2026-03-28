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
        Schema::table('repair_packages', function (Blueprint $table) {
            // Approval workflow fields
            $table->string('old_package_price')->nullable()->after('package_price');
            $table->text('change_reason')->nullable()->after('old_package_price');
            $table->enum('approval_status', [
                'none',           // No pending approval
                'pending_finance', // Waiting for Finance approval
                'finance_approved', // Finance approved, may wait for owner
                'finance_rejected', // Finance rejected
                'pending_owner',   // Waiting for owner approval
                'owner_approved',   // Owner approved
                'owner_rejected',  // Owner rejected
                'finalized'        // All approvals complete, price applied
            ])->default('none')->after('status');
            
            // Approval tracking
            $table->unsignedBigInteger('finance_reviewed_by')->nullable();
            $table->timestamp('finance_reviewed_at')->nullable();
            $table->text('finance_notes')->nullable();
            
            $table->unsignedBigInteger('owner_reviewed_by')->nullable();
            $table->timestamp('owner_reviewed_at')->nullable();
            $table->text('owner_notes')->nullable();
            
            // Multi-level approval tracking
            $table->string('approval_workflow_version')->nullable();
            $table->integer('current_approval_level')->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            
            // Add indexes
            $table->foreign('finance_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('owner_reviewed_by')->references('id')->on('shop_owners')->onDelete('set null');
            
            $table->index(['shop_owner_id', 'approval_status']);
            $table->index('approval_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_packages', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['finance_reviewed_by']);
            $table->dropForeignKeyIfExists(['owner_reviewed_by']);
            $table->dropIndex(['shop_owner_id', 'approval_status']);
            $table->dropIndex(['approval_status']);
            
            $table->dropColumn([
                'old_package_price',
                'change_reason',
                'approval_status',
                'finance_reviewed_by',
                'finance_reviewed_at',
                'finance_notes',
                'owner_reviewed_by',
                'owner_reviewed_at',
                'owner_notes',
                'approval_workflow_version',
                'current_approval_level',
                'approval_id',
            ]);
        });
    }
};
