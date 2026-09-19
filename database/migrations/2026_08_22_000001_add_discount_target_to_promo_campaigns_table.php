<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_campaigns', function (Blueprint $table): void {
            $table->string('discount_target')->default('items')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('promo_campaigns', function (Blueprint $table): void {
            $table->dropColumn('discount_target');
        });
    }
};
