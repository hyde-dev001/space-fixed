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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            $table->enum('leave_type', [
                'vacation', 
                'sick', 
                'personal', 
                'maternity', 
                'paternity', 
                'unpaid'
            ]);
            
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('no_of_days');
            $table->text('reason');
            
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('approver_comments')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('employee_id');
            $table->index('shop_owner_id');
            $table->index('status');
            $table->index('leave_type');
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};