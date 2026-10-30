<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_fee_threshold_states', function (Blueprint $table): void {
            $table->unsignedInteger('crossing_count')->default(0)->after('crossed');
        });
    }

    public function down(): void
    {
        Schema::table('platform_fee_threshold_states', function (Blueprint $table): void {
            $table->dropColumn('crossing_count');
        });
    }
};
