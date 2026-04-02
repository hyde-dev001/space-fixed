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

        DB::statement("ALTER TABLE purchase_requests MODIFY COLUMN status ENUM('draft','pending_finance','pending_shop_owner','pending_finance_final','approved','rejected') NOT NULL DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("UPDATE purchase_requests SET status='pending_finance' WHERE status='pending_finance_final'");
            return;
        }

        DB::statement("UPDATE purchase_requests SET status='pending_finance' WHERE status='pending_finance_final'");
        DB::statement("ALTER TABLE purchase_requests MODIFY COLUMN status ENUM('draft','pending_finance','pending_shop_owner','approved','rejected') NOT NULL DEFAULT 'draft'");
    }
};
