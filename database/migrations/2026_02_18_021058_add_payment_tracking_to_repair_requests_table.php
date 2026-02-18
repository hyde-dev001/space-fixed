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
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->string('paymongo_link_id')->nullable()->after('total');
            $table->string('paymongo_payment_id')->nullable()->after('paymongo_link_id');
            $table->timestamp('payment_link_created_at')->nullable()->after('paymongo_payment_id');
            $table->timestamp('payment_completed_at')->nullable()->after('payment_link_created_at');
            $table->string('payment_status')->default('pending')->after('payment_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropColumn([
                'paymongo_link_id',
                'paymongo_payment_id',
                'payment_link_created_at',
                'payment_completed_at',
                'payment_status',
            ]);
        });
    }
};
