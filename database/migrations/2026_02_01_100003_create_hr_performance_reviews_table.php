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
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            $table->string('reviewer_name');
            $table->date('review_date');
            $table->string('review_period'); // e.g., "Q1 2026" or "2026"
            
            // Rating system (1-5 scale)
            $table->integer('overall_rating')->default(1);
            $table->integer('communication_skills')->default(1);
            $table->integer('teamwork_collaboration')->default(1);
            $table->integer('reliability_responsibility')->default(1);
            $table->integer('productivity_efficiency')->default(1);
            
            // Detailed feedback
            $table->text('comments')->nullable();
            $table->text('goals')->nullable();
            $table->text('improvement_areas')->nullable();
            
            $table->enum('status', ['draft', 'submitted', 'completed'])->default('draft');
            
            $table->timestamps();
            
            // Indexes
            $table->index('employee_id');
            $table->index('shop_owner_id');
            $table->index('review_date');
            $table->index('status');
            $table->index('review_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};