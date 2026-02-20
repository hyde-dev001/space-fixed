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
        Schema::table('notifications', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['user_id']);
            
            // Make columns nullable
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('shop_id')->nullable()->change();
            
            // Re-add foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Drop foreign key
            $table->dropForeign(['user_id']);
            
            // Make columns non-nullable
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
            
            // Re-add foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
