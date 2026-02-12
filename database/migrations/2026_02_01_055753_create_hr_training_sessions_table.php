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
        Schema::create('hr_training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_program_id')->constrained('hr_training_programs')->onDelete('cascade');
            $table->string('session_name');
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location')->nullable();
            $table->text('online_meeting_link')->nullable();
            $table->integer('available_seats')->nullable();
            $table->integer('enrolled_count')->default(0);
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            $table->string('instructor_name')->nullable();
            $table->text('session_notes')->nullable();
            $table->unsignedBigInteger('shop_owner_id');
            $table->timestamps();
            
            $table->index(['training_program_id', 'status']);
            $table->index(['shop_owner_id', 'start_date']);
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_training_sessions');
    }
};
