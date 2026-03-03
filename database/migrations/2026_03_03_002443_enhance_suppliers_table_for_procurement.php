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
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'purchase_order_count')) {
                $table->integer('purchase_order_count')->default(0)->after('is_active');
            }
            if (!Schema::hasColumn('suppliers', 'last_order_date')) {
                $table->timestamp('last_order_date')->nullable()->after('purchase_order_count');
            }
            if (!Schema::hasColumn('suppliers', 'total_order_value')) {
                $table->decimal('total_order_value', 15, 2)->default(0.00)->after('last_order_date');
            }
            if (!Schema::hasColumn('suppliers', 'performance_rating')) {
                $table->decimal('performance_rating', 3, 2)->nullable()->comment('Rating from 1.00 to 5.00')->after('total_order_value');
            }
            if (!Schema::hasColumn('suppliers', 'products_supplied')) {
                $table->text('products_supplied')->nullable()->comment('Comma-separated list or JSON')->after('performance_rating');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_order_count',
                'last_order_date',
                'total_order_value',
                'performance_rating',
                'products_supplied'
            ]);
        });
    }
};
