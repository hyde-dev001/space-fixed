<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated employees table migration
 * 
 * Combines fields from:
 * - 2026_01_15_150001 (base create_employees)
 * - 2026_01_24_200000 (add_branch_and_functional_role)
 * - 2026_01_27_091200 (add_phone - already in base)
 * - 2026_01_27_100000 (add_hr_fields)
 * - 2026_01_27_104000 (add_password)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            // Basic employee information
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->default('');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('profile_photo')->nullable();
            
            // HR Fields
            $table->string('position')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('department')->nullable(); // Keep string field for backward compatibility
            $table->string('branch')->nullable();
            $table->string('functional_role')->nullable();
            
            // Compensation
            $table->decimal('salary', 12, 2)->nullable();
            $table->date('hire_date')->nullable();
            
            // Status
            $table->enum('status', ['active', 'inactive', 'on_leave', 'suspended'])->default('active');
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('shop_owner_id');
            $table->index('email');
            $table->index('status');
            $table->index('department');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
