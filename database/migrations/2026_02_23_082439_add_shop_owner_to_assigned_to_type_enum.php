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
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN assigned_to_type ENUM('crm', 'repairer', 'shop_owner') NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN assigned_to_type ENUM('crm', 'repairer') NULL");
        }
    }
};
