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
        // Modify the enum to add INVENTORY_MANAGER
        // MODIFY COLUMN is MySQL-only; SQLite tests skip this
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'MANAGER',
                'FINANCE',
                'HR',
                'CRM',
                'REPAIRER',
                'INVENTORY',
                'INVENTORY_MANAGER',
                'STAFF',
                'SUPER_ADMIN'
            ) NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original enum
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'MANAGER',
                'FINANCE',
                'HR',
                'CRM',
                'REPAIRER',
                'INVENTORY',
                'STAFF',
                'SUPER_ADMIN'
            ) NULL");
        }
    }
};
