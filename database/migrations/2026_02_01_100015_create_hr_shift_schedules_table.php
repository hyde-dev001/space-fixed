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
        Schema::create('hr_shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('shift_id');
            $table->date('scheduled_date');
            $table->time('actual_start_time')->nullable();
            $table->time('actual_end_time')->nullable();
            $table->integer('actual_break_duration')->nullable()->comment('Actual break taken in minutes');
            $table->decimal('total_hours', 5, 2)->nullable()->comment('Total hours worked');
            $table->decimal('regular_hours', 5, 2)->nullable()->comment('Regular hours within shift');
            $table->decimal('overtime_hours', 5, 2)->nullable()->comment('Overtime hours beyond shift');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'absent', 'late', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('shop_owner_id');
            $table->index('employee_id');
            $table->index('shift_id');
            $table->index('scheduled_date');
            $table->index(['employee_id', 'scheduled_date']);
            $table->index(['shop_owner_id', 'scheduled_date', 'status']);
            $table->index('status');
            
            // Foreign keys
            $table->foreign('shop_owner_id')
                  ->references('id')
                  ->on('shop_owners')
                  ->onDelete('cascade');
                  
            $table->foreign('employee_id')
                  ->references('id')
                  ->on('employees')
                  ->onDelete('cascade');
                  
            $table->foreign('shift_id')
                  ->references('id')
                  ->on('hr_shifts')
                  ->onDelete('cascade');
            
            // Unique constraint - one schedule per employee per date
            $table->unique(['employee_id', 'scheduled_date'], 'emp_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_shift_schedules');
    }
};
