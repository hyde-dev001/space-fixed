<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('order_refunds', 'other_reason_note')) {
                $table->text('other_reason_note')->nullable()->after('reason_note');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'cancellation_other_reason_note')) {
                $table->text('cancellation_other_reason_note')->nullable()->after('refund_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            if (Schema::hasColumn('order_refunds', 'other_reason_note')) {
                $table->dropColumn('other_reason_note');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cancellation_other_reason_note')) {
                $table->dropColumn('cancellation_other_reason_note');
            }
        });
    }
};
