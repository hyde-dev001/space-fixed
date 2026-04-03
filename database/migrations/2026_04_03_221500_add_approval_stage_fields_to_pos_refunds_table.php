<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_refunds', 'finance_status')) {
                $table->string('finance_status', 30)->default('pending')->after('status');
            }

            if (!Schema::hasColumn('pos_refunds', 'shop_owner_status')) {
                $table->string('shop_owner_status', 30)->default('pending')->after('finance_status');
            }

            $table->index(['finance_status', 'shop_owner_status'], 'pos_refunds_approval_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            if (Schema::hasColumn('pos_refunds', 'finance_status')) {
                $table->dropColumn('finance_status');
            }

            if (Schema::hasColumn('pos_refunds', 'shop_owner_status')) {
                $table->dropColumn('shop_owner_status');
            }

            $table->dropIndex('pos_refunds_approval_stage_idx');
        });
    }
};
