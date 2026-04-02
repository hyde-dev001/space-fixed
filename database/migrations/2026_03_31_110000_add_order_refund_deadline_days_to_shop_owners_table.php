<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->unsignedSmallInteger('order_refund_deadline_days')
                ->default(7)
                ->after('repair_workload_limit');
        });

        DB::table('shop_owners')
            ->whereNull('order_refund_deadline_days')
            ->update(['order_refund_deadline_days' => 7]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn('order_refund_deadline_days');
        });
    }
};
