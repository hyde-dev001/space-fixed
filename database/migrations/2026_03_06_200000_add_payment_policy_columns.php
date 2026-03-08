<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add flexible payment policy to shop_owners and repair_requests.
 *
 * Three policies:
 *   deposit_50  – 50 % deposit before drop-off, 50 % remaining at pickup  (current default)
 *   full_upfront – 100 % paid before drop-off, no second payment
 *   pay_after    – No payment before work, 100 % collected at pickup
 */
return new class extends Migration
{
    public function up(): void
    {
        // Shop owner chooses their default policy for all new repairs
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->string('repair_payment_policy', 20)
                  ->default('deposit_50')
                  ->after('require_two_way_approval')
                  ->comment('deposit_50 | full_upfront | pay_after');
        });

        // Each repair records the policy that was active at booking time
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->string('payment_policy', 20)
                  ->default('deposit_50')
                  ->after('payment_enabled_by')
                  ->comment('deposit_50 | full_upfront | pay_after');
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn('repair_payment_policy');
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropColumn('payment_policy');
        });
    }
};
