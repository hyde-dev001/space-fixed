<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('requested_size', 20)->nullable()->after('inventory_item_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('requested_size', 20)->nullable()->after('inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('requested_size');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('requested_size');
        });
    }
};
