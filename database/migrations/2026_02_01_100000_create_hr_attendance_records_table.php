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