<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->dropUnique('purchase_requests_pr_number_unique');
            $table->unique(
                ['shop_owner_id', 'pr_number'],
                'purchase_requests_shop_pr_number_unique'
            );
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_orders_po_number_unique');
            $table->unique(
                ['shop_owner_id', 'po_number'],
                'purchase_orders_shop_po_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_orders_shop_po_number_unique');
            $table->unique('po_number', 'purchase_orders_po_number_unique');
        });

        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->dropUnique('purchase_requests_shop_pr_number_unique');
            $table->unique('pr_number', 'purchase_requests_pr_number_unique');
        });
    }
};
