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
        Schema::table('finance_tax_rates', function (Blueprint $table) {
            // Old global unique index on `code`
            $table->dropUnique('finance_tax_rates_code_unique');

            // New uniqueness scoped per shop
            $table->unique(['shop_id', 'code'], 'finance_tax_rates_shop_id_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_tax_rates', function (Blueprint $table) {
            $table->dropUnique('finance_tax_rates_shop_id_code_unique');
            $table->unique('code', 'finance_tax_rates_code_unique');
        });
    }
};
