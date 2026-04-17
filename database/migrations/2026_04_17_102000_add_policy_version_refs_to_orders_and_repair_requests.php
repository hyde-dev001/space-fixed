<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('accepted_shop_policy_version_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('shop_policy_versions')
                ->nullOnDelete();
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->foreignId('accepted_shop_policy_version_id')
                ->nullable()
                ->after('payment_policy')
                ->constrained('shop_policy_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_shop_policy_version_id');
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_shop_policy_version_id');
        });
    }
};
