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
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_request_approvals', 'requested_color')) {
                $table->string('requested_color', 50)->nullable()->after('requested_size');
            }
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'requested_color')) {
                $table->string('requested_color', 50)->nullable()->after('requested_size');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'requested_color')) {
                $table->string('requested_color', 50)->nullable()->after('requested_size');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('stock_request_approvals', 'requested_color')) {
                $table->dropColumn('requested_color');
            }
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'requested_color')) {
                $table->dropColumn('requested_color');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'requested_color')) {
                $table->dropColumn('requested_color');
            }
        });
    }
};
