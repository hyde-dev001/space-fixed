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
        Schema::create('suspension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade'); // HR person who filed
            $table->text('reason');
            $table->text('evidence')->nullable();
            
            // Overall status
            $table->enum('status', ['pending_manager', 'pending_owner', 'approved', 'rejected_manager', 'rejected_owner'])->default('pending_manager');
            
            // Manager review
            $table->foreignId('manager_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('manager_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('manager_note')->nullable();
            $table->timestamp('manager_reviewed_at')->nullable();
            
            // Shop Owner review (final approval)
            $table->foreignId('owner_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('owner_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('owner_note')->nullable();
            $table->timestamp('owner_reviewed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('employee_id');
            $table->index('status');
            $table->index('requested_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspension_requests');
    }
};
