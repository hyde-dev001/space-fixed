<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('origin_channel', 30)->nullable()->after('shop_owner_id')->index();
        });

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->string('origin_channel', 30)->nullable()->after('shop_owner_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropIndex(['origin_channel']);
            $table->dropColumn('origin_channel');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['origin_channel']);
            $table->dropColumn('origin_channel');
        });
    }
};
