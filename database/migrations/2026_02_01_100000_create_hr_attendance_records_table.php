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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            $table->date('date');
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            
            // Auto clock-out tracking
            $table->boolean('auto_clocked_out')->default(false);
            $table->string('auto_clockout_reason')->nullable();
            
            // Expected times and lateness tracking
            $table->time('expected_check_in')->nullable();
            $table->time('expected_check_out')->nullable();
            $table->integer('minutes_late')->default(0);
            $table->integer('minutes_early_departure')->default(0);
            $table->boolean('is_late')->default(false);
            $table->boolean('is_early_departure')->default(false);
            $table->text('lateness_reason')->nullable();
            
            // Early check-in tracking
            $table->boolean('is_early')->default(false);
            $table->integer('minutes_early')->default(0);
            $table->text('early_reason')->nullable();
            
            // Seamless overtime integration
            $table->boolean('has_approved_overtime')->default(false);
            $table->time('overtime_end_time')->nullable();
            
            $table->enum('status', ['present', 'absent', 'late', 'half_day'])->default('present');
            $table->string('biometric_id')->nullable();
            $table->text('notes')->nullable();
            
            $table->decimal('working_hours', 4, 2)->default(0);
            $table->decimal('overtime_hours', 4, 2)->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index(['employee_id', 'date']);
            $table->index('shop_owner_id');
            $table->index('date');
            $table->index('status');
            $table->index('is_late');
            $table->index(['date', 'is_late']);
            
            // Unique constraint to prevent duplicate attendance records
            $table->unique(['employee_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};