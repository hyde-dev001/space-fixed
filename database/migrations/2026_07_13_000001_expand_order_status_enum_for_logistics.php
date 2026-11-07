<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','processing','shipped','delivered','completed','cancelled','refund') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // Existing completed/refund orders make narrowing this enum unsafe.
    }
};
