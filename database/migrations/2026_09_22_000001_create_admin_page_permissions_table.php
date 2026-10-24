<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_page_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->string('page_key', 64);
            $table->timestamps();

            $table->unique(['super_admin_id', 'page_key']);
            $table->index('page_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_page_permissions');
    }
};
