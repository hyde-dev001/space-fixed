<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'vat_amount')) {
                $table->decimal('vat_amount', 10, 2)->nullable()->after('shipping_fee');
            }

            if (!Schema::hasColumn('orders', 'vat_rate')) {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }

            if (Schema::hasColumn('orders', 'vat_amount')) {
                $table->dropColumn('vat_amount');
            }
        });
    }
};
