<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_requests', 'paymongo_payment_ids')) {
                $table->json('paymongo_payment_ids')->nullable()->after('paymongo_payment_id');
            }
        });

        Schema::table('pos_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_refunds', 'paymongo_payment_ids')) {
                $table->json('paymongo_payment_ids')->nullable()->after('paymongo_payment_id');
            }

            if (!Schema::hasColumn('pos_refunds', 'paymongo_refund_ids')) {
                $table->json('paymongo_refund_ids')->nullable()->after('paymongo_refund_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('pos_refunds', 'paymongo_payment_ids')) {
                $dropColumns[] = 'paymongo_payment_ids';
            }

            if (Schema::hasColumn('pos_refunds', 'paymongo_refund_ids')) {
                $dropColumns[] = 'paymongo_refund_ids';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            if (Schema::hasColumn('repair_requests', 'paymongo_payment_ids')) {
                $table->dropColumn('paymongo_payment_ids');
            }
        });
    }
};
