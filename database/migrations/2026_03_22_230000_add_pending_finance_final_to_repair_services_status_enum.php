<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `repair_services` MODIFY COLUMN `status` ENUM('Active', 'Inactive', 'Pending', 'Under Review', 'Pending Owner Approval', 'Pending Finance Final Approval', 'Rejected') DEFAULT 'Active'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `repair_services` MODIFY COLUMN `status` ENUM('Active', 'Inactive', 'Pending', 'Under Review', 'Pending Owner Approval', 'Rejected') DEFAULT 'Active'");
        }
    }
};
