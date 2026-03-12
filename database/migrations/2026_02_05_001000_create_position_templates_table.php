<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Position Templates Migration
 * 
 * Creates tables for storing position templates (permission presets)
 * that can be quickly applied to users during onboarding
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('position_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., "Cashier", "Bookkeeper"
            $table->string('slug')->unique(); // e.g., "cashier", "bookkeeper"
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // e.g., "Finance", "HR", "General"
            $table->string('recommended_role')->nullable(); // Recommended Spatie role
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0); // Track how many times applied
            $table->timestamps();
            
            $table->index('category');
            $table->index('is_active');
        });

        Schema::create('position_template_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_template_id')
                ->constrained('position_templates')
                ->onDelete('cascade');
            $table->string('permission_name'); // Permission name from Spatie
            $table->timestamps();
            
            $table->unique(['position_template_id', 'permission_name'], 'template_permission_unique');
            $table->index('permission_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('position_template_permissions');
        Schema::dropIfExists('position_templates');
    }
};
