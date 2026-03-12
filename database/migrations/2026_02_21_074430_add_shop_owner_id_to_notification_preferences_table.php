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
        Schema::table('notification_preferences', function (Blueprint $table) {
            // Make user_id nullable since shop owners will use shop_owner_id
            $table->unsignedBigInteger('user_id')->nullable()->change();
            
            // Add shop_owner_id for shop owner notification preferences
            $table->unsignedBigInteger('shop_owner_id')->nullable()->after('user_id');
            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('cascade');
            $table->index('shop_owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropForeign(['shop_owner_id']);
            $table->dropIndex(['shop_owner_id']);
            $table->dropColumn('shop_owner_id');
            
            // Restore user_id to not nullable
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
