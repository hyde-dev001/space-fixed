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
        Schema::table('delivery_incidents', function (Blueprint $table) {
            $table->json('recovery_context')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('delivery_incidents')->whereNotNull('recovery_context')->exists()) {
            throw new RuntimeException('Retain incident recovery evidence. A forward migration is required.');
        }
        Schema::table('delivery_incidents', function (Blueprint $table) {
            $table->dropColumn('recovery_context');
        });
    }
};
