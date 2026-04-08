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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('scheduled_sale_price', 10, 2)->nullable()->after('compare_at_price');
            $table->dateTime('sale_starts_at')->nullable()->after('scheduled_sale_price');
            $table->dateTime('sale_ends_at')->nullable()->after('sale_starts_at');

            $table->index('sale_starts_at');
            $table->index('sale_ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sale_starts_at']);
            $table->dropIndex(['sale_ends_at']);

            $table->dropColumn([
                'scheduled_sale_price',
                'sale_starts_at',
                'sale_ends_at',
            ]);
        });
    }
};
