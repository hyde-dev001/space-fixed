<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('job_order_id');

            $table->foreign('purchase_order_id')
                  ->references('id')
                  ->on('supplier_orders')
                  ->onDelete('set null');

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropIndex(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });
    }
};
