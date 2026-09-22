<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->boolean('cod_enabled')
                ->default(false)
                ->after('order_refund_deadline_days');
            $table->decimal('cod_order_threshold', 12, 2)
                ->default(5000.00)
                ->after('cod_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->dropColumn(['cod_enabled', 'cod_order_threshold']);
        });
    }
};
