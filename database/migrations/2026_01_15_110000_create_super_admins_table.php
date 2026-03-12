<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

/**
 * Create super_admins table
 * 
 * This table stores super administrator accounts with full system access.
 * Super admins can:
 * - Approve/reject shop owner registrations
 * - Manage all user accounts
 * - Access system analytics and reports
 * - Configure system settings
 * 
 * Security Features:
 * - Passwords are hashed using bcrypt
 * - Email must be unique
 * - Status field allows account suspension
 * - Last login tracking for security audit
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates super_admins table and seeds default admin account
     */
    public function up(): void
    {
        Schema::create('super_admins', function (Blueprint $table) {
            // <!-- Primary key -->
            $table->id();

            // <!-- Personal Information -->
            $table->string('first_name');              // Admin's first name
            $table->string('last_name');               // Admin's last name

            // <!-- Authentication credentials -->
            $table->string('email')->unique();         // Login email (must be unique)
            $table->string('password');                // Hashed password using bcrypt

            // <!-- Contact Information -->
            $table->string('phone');                   // Phone number for contact

            // <!-- Role and Permissions -->
            // <!-- admin: Standard admin access -->
            // <!-- super_admin: Full system access -->
            $table->enum('role', ['admin', 'super_admin'])->default('admin');

            // <!-- Account status -->
            // <!-- active: Can login and access system -->
            // <!-- suspended: Account temporarily disabled -->
            // <!-- inactive: Account permanently disabled -->
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');

            // <!-- Security tracking -->
            $table->timestamp('last_login_at')->nullable();  // Track last successful login
            $table->string('last_login_ip')->nullable();     // Store IP address for security audit

            // <!-- Remember token for "Remember Me" functionality -->
            $table->rememberToken();

            // <!-- Timestamps (created_at, updated_at) -->
            $table->timestamps();
        });

        // <!-- Create default super admin account -->
        // <!-- IMPORTANT: Change these credentials in production! -->
        DB::table('super_admins')->insert([
            'first_name' => 'Super',
            'last_name' => 'Administrator',
            'email' => 'admin@thesis.com',
            'password' => Hash::make('admin123'),  // Default password: admin123
            'phone' => '09123456789',
            'role' => 'super_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     * 
     * Drops the super_admins table
     */
    public function down(): void
    {
        Schema::dropIfExists('super_admins');
    }
};
