<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->unsignedTinyInteger('late_grace_period_minutes')->default(0);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('attendance_camera_prompt_dismissed')->default(false);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('check_in_photo_path')->nullable();
            $table->timestamp('check_in_photo_captured_at')->nullable();
            $table->unsignedTinyInteger('late_grace_period_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_photo_path',
                'check_in_photo_captured_at',
                'late_grace_period_minutes',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('attendance_camera_prompt_dismissed');
        });

        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn('late_grace_period_minutes');
        });
    }
};
