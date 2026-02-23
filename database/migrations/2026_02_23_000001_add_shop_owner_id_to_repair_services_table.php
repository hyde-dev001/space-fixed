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
        Schema::table('repair_services', function (Blueprint $table) {
            $table->foreignId('shop_owner_id')->nullable()->after('status')->constrained('shop_owners')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_services', function (Blueprint $table) {
            $table->dropForeign(['shop_owner_id']);
            $table->dropColumn('shop_owner_id');
        });
    }
};
