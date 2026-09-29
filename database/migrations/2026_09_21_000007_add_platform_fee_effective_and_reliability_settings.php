<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_fee_settings', function (Blueprint $table): void {
            $table->timestamp('effective_from')->nullable()->after('enforcement_enabled');
            $table->unsignedInteger('reliability_window_days')->nullable()->after('effective_from');
            $table->string('reliability_version', 40)->nullable()->after('reliability_window_days');
            $table->json('reliability_weights')->nullable()->after('reliability_version');
            $table->json('reliability_tiers')->nullable()->after('reliability_weights');
        });
    }

    public function down(): void
    {
        Schema::table('platform_fee_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'effective_from',
                'reliability_window_days',
                'reliability_version',
                'reliability_weights',
                'reliability_tiers',
            ]);
        });
    }
};
