<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privileged_sessions', function (Blueprint $table): void {
            $table->string('session_id')->primary();
            $table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->unsignedBigInteger('security_version');
            $table->timestamp('authenticated_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->index(['super_admin_id', 'security_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privileged_sessions');
    }
};
