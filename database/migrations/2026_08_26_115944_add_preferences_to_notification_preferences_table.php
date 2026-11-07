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
            $table->json('preferences')->nullable();
            $table->string('email_digest_frequency')->default('none');
            $table->boolean('sound_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'preferences',
                'email_digest_frequency',
                'sound_enabled',
            ]);
        });
    }
};
