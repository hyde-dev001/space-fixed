<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated shop_owners migration
 * 
 * Combines fields from:
 * - 2026_01_14_205002 (base table)
 * - 2026_01_15_100004 (add_monthly_target)
 * - 2026_01_16_120500 (add_rejection_reason)
 * - 2026_01_18_051834 (add_suspension_reason)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('shop_owners', function (Blueprint $table) {
            // Primary key
            $table->id();
            
            // Personal Information
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('password')->nullable();
            
            // Business Information
            $table->string('business_name');
            $table->string('business_address');
            $table->string('business_type'); // retail, repair, or both
            $table->string('registration_type'); // individual or company
            
            // Operating Schedule
            $table->json('operating_hours')->nullable();
            
            // Approval Status & Reasons
            $table->string('status')->default('pending'); // pending, approved, rejected, suspended
            $table->string('rejection_reason', 500)->nullable();
            $table->text('suspension_reason')->nullable();
            
            // Business Targets
            $table->decimal('monthly_target', 15, 2)->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('email');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_owners');
    }
};
