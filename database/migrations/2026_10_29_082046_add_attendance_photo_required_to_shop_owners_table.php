<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->boolean('attendance_photo_required')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn('attendance_photo_required');
        });
    }
};
