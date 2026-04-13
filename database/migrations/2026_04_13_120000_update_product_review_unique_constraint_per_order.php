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
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropUnique('unique_product_user_review');
            $table->unique(['product_id', 'user_id', 'order_id'], 'unique_product_user_order_review');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropUnique('unique_product_user_order_review');
            $table->unique(['product_id', 'user_id'], 'unique_product_user_review');
        });
    }
};
