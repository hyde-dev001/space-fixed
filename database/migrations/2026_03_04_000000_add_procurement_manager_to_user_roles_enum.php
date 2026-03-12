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
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'MANAGER',
            'FINANCE',
            'HR',
            'CRM',
            'REPAIRER',
            'INVENTORY',
            'INVENTORY_MANAGER',
            'PROCUREMENT_MANAGER',
            'STAFF',
            'SUPER_ADMIN'
        ) NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
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
};
