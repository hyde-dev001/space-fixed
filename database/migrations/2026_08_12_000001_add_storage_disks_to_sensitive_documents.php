<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_documents', function (Blueprint $table): void {
            $table->string('disk', 32)->default('public')->after('file_path');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('valid_id_disk', 32)->default('public')->after('valid_id_path');
        });
    }

    public function down(): void
    {
        Schema::table('shop_documents', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('valid_id_disk');
        });
    }
};
