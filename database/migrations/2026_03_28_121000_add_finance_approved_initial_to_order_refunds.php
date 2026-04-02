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
        if (DB::getDriverName() === 'sqlite') {
            // SQLite does not support ALTER TABLE ... MODIFY COLUMN with ENUM definitions.
            return;
        }

        DB::statement("ALTER TABLE order_refunds MODIFY COLUMN finance_status ENUM('pending','approved_initial','approved','rejected') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("UPDATE order_refunds SET finance_status='approved' WHERE finance_status='approved_initial'");
            return;
        }

        DB::statement("UPDATE order_refunds SET finance_status='approved' WHERE finance_status='approved_initial'");
        DB::statement("ALTER TABLE order_refunds MODIFY COLUMN finance_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
};
