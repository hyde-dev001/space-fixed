<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_credit_applications', function (Blueprint $table): void {
            $table->foreignId('platform_fee_charge_id')
                ->nullable()
                ->after('shop_id')
                ->constrained('platform_fee_charges')
                ->nullOnDelete();

            $table->index(['platform_fee_charge_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_credit_applications', function (Blueprint $table): void {
            $table->dropForeign(['platform_fee_charge_id']);
            $table->dropIndex(['platform_fee_charge_id', 'status']);
            $table->dropColumn('platform_fee_charge_id');
        });
    }
};
