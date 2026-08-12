<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privileged_security_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->foreignId('created_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('purpose', 32);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['super_admin_id', 'purpose', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privileged_security_tokens');
    }
};
