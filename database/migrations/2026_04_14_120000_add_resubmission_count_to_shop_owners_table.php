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
        Schema::table('shop_owners', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_owners', 'resubmission_count')) {
                $table->unsignedTinyInteger('resubmission_count')
                    ->default(0)
                    ->after('rejection_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            if (Schema::hasColumn('shop_owners', 'resubmission_count')) {
                $table->dropColumn('resubmission_count');
            }
        });
    }
};
