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
            $table->string('registration_terms_version', 80)
                ->nullable()
                ->after('registration_type');
            $table->timestamp('registration_terms_accepted_at')
                ->nullable()
                ->after('registration_terms_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn([
                'registration_terms_version',
                'registration_terms_accepted_at',
            ]);
        });
    }
};
