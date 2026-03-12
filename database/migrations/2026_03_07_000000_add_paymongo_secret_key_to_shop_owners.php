<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->text('paymongo_secret_key')->nullable()->after('repair_payment_policy');
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn('paymongo_secret_key');
        });
    }
};
