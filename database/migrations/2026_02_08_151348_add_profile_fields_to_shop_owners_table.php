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
            $table->string('profile_photo')->nullable()->after('email');
            $table->text('bio')->nullable()->after('profile_photo');
            $table->string('country')->nullable()->after('business_address');
            $table->string('city_state')->nullable()->after('country');
            $table->string('postal_code')->nullable()->after('city_state');
            $table->string('tax_id')->nullable()->after('postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn(['profile_photo', 'bio', 'country', 'city_state', 'postal_code', 'tax_id']);
        });
    }
};
