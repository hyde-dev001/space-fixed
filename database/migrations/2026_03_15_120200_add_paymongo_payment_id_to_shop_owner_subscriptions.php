<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            // Track the PayMongo payment ID that completed/activated this subscription.
            // Stored after webhook confirmation so we have an audit trail.
            $table->string('paymongo_payment_id')->nullable()->after('paymongo_session_id');
            $table->index('paymongo_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['paymongo_payment_id']);
            $table->dropColumn('paymongo_payment_id');
        });
    }
};
