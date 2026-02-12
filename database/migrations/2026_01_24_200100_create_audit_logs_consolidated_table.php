<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated audit_logs table migration
 * 
 * Consolidates:
 * - 2026_01_24_200100 (detailed version with shop_owner_id)
 * - 2026_01_28_000004 (simpler version - DISCARDED)
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('shop_owner_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('action');
                $table->string('object_type')->nullable();
                $table->string('target_type')->nullable();
                $table->unsignedBigInteger('object_id')->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->json('data')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                
                // Indexes for performance
                $table->index(['shop_owner_id', 'action']);
                $table->index(['target_type', 'target_id']);
                $table->index('user_id');
                $table->index('action');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('audit_logs');
    }
};
