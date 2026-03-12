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
        DB::statement("ALTER TABLE conversation_messages MODIFY COLUMN sender_type ENUM('customer', 'crm', 'repairer', 'shop_owner', 'system')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE conversation_messages MODIFY COLUMN sender_type ENUM('customer', 'crm', 'repairer')");
    }
};
