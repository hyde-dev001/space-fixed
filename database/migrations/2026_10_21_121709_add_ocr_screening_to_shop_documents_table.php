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
        Schema::table('shop_documents', function (Blueprint $table): void {
            $table->json('ocr_screening')->nullable()->after('checksum_sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_documents', function (Blueprint $table): void {
            $table->dropColumn('ocr_screening');
        });
    }
};
