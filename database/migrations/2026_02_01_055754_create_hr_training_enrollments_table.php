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
        Schema::create('hr_training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_program_id')->constrained('hr_training_programs')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('training_session_id')->nullable()->constrained('hr_training_sessions')->onDelete('set null');
            $table->enum('status', ['enrolled', 'in_progress', 'completed', 'failed', 'cancelled', 'no_show'])->default('enrolled');
            $table->date('enrolled_date');
            $table->date('start_date')->nullable();
            $table->date('completion_date')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->decimal('assessment_score', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->text('feedback')->nullable();
            $table->integer('attendance_hours')->default(0);
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('completion_notes')->nullable();
            $table->unsignedBigInteger('shop_owner_id');
            $table->timestamps();
            
            $table->index(['employee_id', 'status']);
            $table->index(['training_program_id', 'status']);
            $table->index(['shop_owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_training_enrollments');
    }
};
