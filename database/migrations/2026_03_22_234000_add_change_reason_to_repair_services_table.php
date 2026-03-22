<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repair_services', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_services', 'change_reason')) {
                $table->text('change_reason')->nullable()->after('description');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_services', function (Blueprint $table) {
            if (Schema::hasColumn('repair_services', 'change_reason')) {
                $table->dropColumn('change_reason');
            }
        });
    }
};
