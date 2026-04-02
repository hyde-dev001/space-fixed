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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancellation_refund_window_started_at')->nullable()->after('paymongo_refund_id');
            $table->unsignedInteger('cancellation_refund_window_minutes')->nullable()->after('cancellation_refund_window_started_at');
        });

        DB::table('orders')
            ->whereNull('cancellation_refund_window_started_at')
            ->update([
                'cancellation_refund_window_started_at' => DB::raw('created_at'),
                'cancellation_refund_window_minutes' => 10080,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'cancellation_refund_window_started_at',
                'cancellation_refund_window_minutes',
            ]);
        });
    }
};
