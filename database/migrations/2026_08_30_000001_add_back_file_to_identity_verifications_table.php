<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->string('back_file_path')->nullable()->after('file_disk');
            $table->string('back_file_disk', 32)->nullable()->after('back_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->dropColumn(['back_file_path', 'back_file_disk']);
        });
    }
};
