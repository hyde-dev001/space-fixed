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
        Schema::create('hr_overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('shift_schedule_id')->nullable()->comment('Link to shift schedule if applicable');
            $table->date('overtime_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('hours', 5, 2);
            $table->decimal('rate_multiplier', 3, 2)->default(1.50)->comment('Overtime pay multiplier');
            $table->decimal('calculated_amount', 10, 2)->nullable()->comment('Calculated overtime pay');
            $table->enum('overtime_type', ['weekday', 'weekend', 'holiday', 'emergency'])->default('weekday');
            $table->text('reason');
            $table->text('work_description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->unsignedBigInteger('payroll_id')->nullable()->comment('Link to payroll when processed');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('shop_owner_id');
            $table->index('employee_id');
            $table->index('shift_schedule_id');
            $table->index('overtime_date');
            $table->index('status');
            $table->index(['employee_id', 'overtime_date']);
            $table->index(['shop_owner_id', 'status']);
            $table->index(['shop_owner_id', 'overtime_date']);
            $table->index('is_paid');
            $table->index('approved_by');
            
            // Foreign keys
            $table->foreign('shop_owner_id')
                  ->references('id')
                  ->on('shop_owners')
                  ->onDelete('cascade');
                  
            $table->foreign('employee_id')
                  ->references('id')
                  ->on('employees')
                  ->onDelete('cascade');
                  
            $table->foreign('shift_schedule_id')
                  ->references('id')
                  ->on('hr_shift_schedules')
                  ->onDelete('set null');
                  
            $table->foreign('approved_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_overtime_requests');
    }
};
