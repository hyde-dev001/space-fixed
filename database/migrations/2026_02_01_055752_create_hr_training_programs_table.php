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
        Schema::create('hr_training_programs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('category', ['technical', 'soft_skills', 'compliance', 'leadership', 'safety', 'product', 'other']);
            $table->enum('delivery_method', ['classroom', 'online', 'hybrid', 'workshop', 'seminar', 'self_paced']);
            $table->integer('duration_hours')->nullable();
            $table->decimal('cost', 10, 2)->default(0);
            $table->integer('max_participants')->nullable();
            $table->text('prerequisites')->nullable();
            $table->text('learning_objectives')->nullable();
            $table->string('instructor_name')->nullable();
            $table->string('instructor_email')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('issues_certificate')->default(false);
            $table->integer('certificate_validity_months')->nullable();
            $table->unsignedBigInteger('shop_owner_id');
            $table->timestamps();
            
            $table->index(['shop_owner_id', 'is_active']);
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_training_programs');
    }
};
