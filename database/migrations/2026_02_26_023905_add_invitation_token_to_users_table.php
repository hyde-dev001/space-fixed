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
        Schema::table('users', function (Blueprint $table) {
            $table->string('invite_token', 64)->unique()->nullable()->after('password');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token');
            $table->timestamp('invited_at')->nullable()->after('invite_expires_at');
            $table->unsignedBigInteger('invited_by')->nullable()->after('invited_at');
            
            $table->index('invite_token');
            $table->foreign('invited_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['invited_by']);
            $table->dropIndex(['invite_token']);
            $table->dropColumn(['invite_token', 'invite_expires_at', 'invited_at', 'invited_by']);
        });
    }
};
