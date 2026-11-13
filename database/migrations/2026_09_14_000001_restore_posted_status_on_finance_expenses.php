<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('finance_expenses') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE `finance_expenses` MODIFY COLUMN `status` "
            . "ENUM('draft', 'submitted', 'approved', 'posted', 'rejected') "
            . "NOT NULL DEFAULT 'submitted'",
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_expenses') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('finance_expenses')
            ->where('status', 'posted')
            ->update(['status' => 'approved']);

        DB::statement(
            "ALTER TABLE `finance_expenses` MODIFY COLUMN `status` "
            . "ENUM('draft', 'submitted', 'approved', 'rejected') "
            . "NOT NULL DEFAULT 'submitted'",
        );
    }
};
