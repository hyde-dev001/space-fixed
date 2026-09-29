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
            $table->string('showroom_left_wall_art_path')->nullable()->after('cover_photo');
            $table->string('showroom_right_wall_art_path')->nullable()->after('showroom_left_wall_art_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn([
                'showroom_left_wall_art_path',
                'showroom_right_wall_art_path',
            ]);
        });
    }
};
