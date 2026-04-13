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
        Schema::table('shop_owners', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_owners', 'two_factor_email_enabled')) {
                $table->boolean('two_factor_email_enabled')->default(false)->after('order_refund_deadline_days');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            if (Schema::hasColumn('shop_owners', 'two_factor_email_enabled')) {
                $table->dropColumn('two_factor_email_enabled');
            }
        });
    }
};
