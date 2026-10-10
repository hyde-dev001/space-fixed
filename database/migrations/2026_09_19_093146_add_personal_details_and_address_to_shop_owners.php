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
            $table->string('suffix', 20)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('address_region')->nullable();
            $table->string('address_province')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_barangay')->nullable();
            $table->string('address_postal_code', 10)->nullable();
            $table->decimal('address_latitude', 10, 8)->nullable();
            $table->decimal('address_longitude', 11, 8)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn([
                'suffix',
                'age',
                'address',
                'address_region',
                'address_province',
                'address_city',
                'address_barangay',
                'address_postal_code',
                'address_latitude',
                'address_longitude',
            ]);
        });
    }
};
