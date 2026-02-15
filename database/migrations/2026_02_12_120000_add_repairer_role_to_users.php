<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, let's check and fix any invalid role values
        // Set any NULL or invalid roles to 'STAFF' as a safe default
        DB::statement("UPDATE users SET role = 'STAFF' WHERE role IS NULL OR role NOT IN ('HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'CRM', 'MANAGER', 'STAFF', 'SUPER_ADMIN', 'PAYROLL_MANAGER')");
        
        // Now we can safely add REPAIRER to user roles enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'CRM', 'REPAIRER', 'MANAGER', 'STAFF', 'SUPER_ADMIN', 'PAYROLL_MANAGER') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set any REPAIRER roles back to STAFF before removing from enum
        DB::statement("UPDATE users SET role = 'STAFF' WHERE role = 'REPAIRER'");
        
        // Remove REPAIRER from user roles enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'CRM', 'MANAGER', 'STAFF', 'SUPER_ADMIN', 'PAYROLL_MANAGER') NULL");
    }
};
