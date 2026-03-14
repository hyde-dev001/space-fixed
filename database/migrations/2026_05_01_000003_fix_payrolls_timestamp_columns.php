<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert TIMESTAMP columns on payrolls to DATETIME so values beyond
     * 2038-01-19 (MySQL TIMESTAMP year limit) are accepted.
     *
     * Affected: payment_date, generated_at, approved_at (all were TIMESTAMP).
     */
    public function up(): void
    {
        // Use raw DDL to avoid Doctrine DBAL's change() limitations with nullable timestamps
        // MODIFY is MySQL-only; SQLite tests skip this (columns stay as their original type)
        if (\DB::getDriverName() !== 'sqlite') {
            \DB::statement('ALTER TABLE `payrolls` MODIFY `payment_date` DATETIME NULL');
            \DB::statement('ALTER TABLE `payrolls` MODIFY `generated_at` DATETIME NULL');
            \DB::statement('ALTER TABLE `payrolls` MODIFY `approved_at`  DATETIME NULL');
        }
    }

    public function down(): void
    {
        if (\DB::getDriverName() !== 'sqlite') {
            \DB::statement('ALTER TABLE `payrolls` MODIFY `payment_date` TIMESTAMP NULL');
            \DB::statement('ALTER TABLE `payrolls` MODIFY `generated_at` TIMESTAMP NULL');
            \DB::statement('ALTER TABLE `payrolls` MODIFY `approved_at`  TIMESTAMP NULL');
        }
    }
};
