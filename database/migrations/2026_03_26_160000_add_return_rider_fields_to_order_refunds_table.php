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
        Schema::table('order_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('order_refunds', 'customer_return_rider_name')) {
                $table->string('customer_return_rider_name')
                    ->nullable()
                    ->after('customer_return_carrier');
            }

            if (!Schema::hasColumn('order_refunds', 'customer_return_rider_phone')) {
                $table->string('customer_return_rider_phone')
                    ->nullable()
                    ->after('customer_return_rider_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            $columns = [
                'customer_return_rider_name',
                'customer_return_rider_phone',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('order_refunds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
