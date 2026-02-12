<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated users table migration
 * 
 * Combines fields from:
 * - 0001_01_01_000000 (base table)
 * - 2026_01_15_100000 (add_role)
 * - 2026_01_16_100000 (add_user_registration_fields)
 * - 2026_01_24_210000 (add_force_password_change)
 * - 2026_01_26_174600 (add_crm_to_user_roles)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Primary key
            $table->id();
            
            // Basic user information
            $table->string('name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            
            // User profile fields
            $table->string('phone', 15)->nullable();
            $table->integer('age')->nullable();
            $table->text('address')->nullable();
            $table->string('valid_id_path')->nullable();
            
            // Role and permissions
            $table->enum('role', [
                'HR', 
                'FINANCE_STAFF', 
                'FINANCE_MANAGER', 
                'CRM', 
                'MANAGER', 
                'STAFF', 
                'SUPER_ADMIN',
                'PAYROLL_MANAGER',
            ])->nullable();
            $table->boolean('force_password_change')->default(false);
            
            // Account status
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            
            // Shop association (for users assigned to specific shops)
            $table->unsignedBigInteger('shop_owner_id')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('email');
            $table->index('role');
            $table->index('status');
            $table->index('shop_owner_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
