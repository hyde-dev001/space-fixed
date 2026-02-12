<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update the role enum to include simplified department roles
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'MANAGER',
            'FINANCE',
            'HR',
            'CRM',
            'STAFF',
            'SUPER_ADMIN'
        ) NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to Manager/Staff only
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'MANAGER',
            'STAFF',
            'SUPER_ADMIN'
        ) NULL");
    }
};
