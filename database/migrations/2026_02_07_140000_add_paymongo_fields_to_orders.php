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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('paymongo_link_id')->nullable()->after('payment_status');
            $table->string('paymongo_payment_id')->nullable()->after('paymongo_link_id');
            $table->timestamp('paid_at')->nullable()->after('paymongo_payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paymongo_link_id', 'paymongo_payment_id', 'paid_at']);
        });
    }
};
