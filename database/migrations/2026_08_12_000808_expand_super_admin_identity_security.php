<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('super_admins', function (Blueprint $table): void {
            $table->text('mfa_secret')->nullable();
            $table->json('mfa_recovery_codes')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable()->index();
            $table->unsignedBigInteger('mfa_last_used_timestep')->nullable();
            $table->unsignedBigInteger('security_version')->default(1);
            $table->timestamp('password_changed_at')->nullable();
            $table->string('bootstrap_marker', 32)->nullable()->unique();
        });

        Schema::table('super_admins', function (Blueprint $table): void {
            $table->string('status', 32)->default('active')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('super_admins')->where('status', 'pending_setup')->exists()) {
            throw new RuntimeException('Cannot roll back privileged identity security while pending setup accounts exist.');
        }

        Schema::table('super_admins', function (Blueprint $table): void {
            $table->dropUnique(['bootstrap_marker']);
            $table->dropColumn([
                'mfa_secret',
                'mfa_recovery_codes',
                'mfa_confirmed_at',
                'mfa_last_used_timestep',
                'security_version',
                'password_changed_at',
                'bootstrap_marker',
            ]);
        });

        Schema::table('super_admins', function (Blueprint $table): void {
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active')->change();
        });
    }
};
