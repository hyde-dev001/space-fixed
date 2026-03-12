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
        // Main approvals table
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->string('approvable_type')->nullable(); // Polymorphic type (e.g., 'App\Models\Expense')
            $table->unsignedBigInteger('approvable_id')->nullable(); // Polymorphic ID
            $table->string('reference'); // Reference number (e.g., EXP-001, JE-123)
            $table->text('description');
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedBigInteger('requested_by'); // User who requested approval
            $table->unsignedBigInteger('reviewed_by')->nullable(); // User who approved/rejected
            $table->timestamp('reviewed_at')->nullable();
            $table->integer('current_level')->default(1); // Current approval level
            $table->integer('total_levels')->default(1); // Total levels required
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->text('comments')->nullable(); // Final approval/rejection comments
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();

            // Foreign keys
            $table->foreign('shop_owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['shop_owner_id', 'status']);
            $table->index(['approvable_type', 'approvable_id']);
            $table->index('reference');
        });

        // Approval history table
        Schema::create('approval_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_id');
            $table->integer('level'); // Approval level when this action was taken
            $table->unsignedBigInteger('reviewer_id'); // User who performed this action
            $table->enum('action', ['approved', 'rejected']); // Action taken
            $table->text('comments')->nullable(); // Comments for this action
            $table->timestamps();

            // Foreign keys
            $table->foreign('approval_id')->references('id')->on('approvals')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes
            $table->index('approval_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_history');
        Schema::dropIfExists('approvals');
    }
};
