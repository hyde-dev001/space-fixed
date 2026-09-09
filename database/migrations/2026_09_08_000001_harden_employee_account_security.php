<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'invite_token_hash')) {
                $table->string('invite_token_hash', 64)->nullable()->unique()->after('invite_token');
            }

            if (! Schema::hasColumn('users', 'security_version')) {
                $table->unsignedInteger('security_version')->default(1)->after('force_password_change');
            }

            if (! Schema::hasColumn('users', 'employee_totp_secret')) {
                $table->text('employee_totp_secret')->nullable()->after('security_version');
            }

            if (! Schema::hasColumn('users', 'employee_totp_enabled_at')) {
                $table->timestamp('employee_totp_enabled_at')->nullable()->after('employee_totp_secret');
            }

            if (! Schema::hasColumn('users', 'employee_totp_recovery_codes')) {
                $table->text('employee_totp_recovery_codes')->nullable()->after('employee_totp_enabled_at');
            }

            if (! Schema::hasColumn('users', 'employee_totp_last_used_timestep')) {
                $table->unsignedBigInteger('employee_totp_last_used_timestep')->nullable()->after('employee_totp_recovery_codes');
            }
        });

        // Preserve existing live invitation links while removing their plaintext
        // representation from the database.
        if (Schema::hasColumn('users', 'invite_token') && Schema::hasColumn('users', 'invite_token_hash')) {
            DB::table('users')
                ->whereNotNull('invite_token')
                ->orderBy('id')
                ->chunkById(100, function ($users): void {
                    foreach ($users as $user) {
                        if (! is_string($user->invite_token) || $user->invite_token === '') {
                            continue;
                        }

                        DB::table('users')
                            ->where('id', $user->id)
                            ->update([
                                'invite_token_hash' => hash('sha256', $user->invite_token),
                                'invite_token' => null,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = [
                'employee_totp_last_used_timestep',
                'employee_totp_recovery_codes',
                'employee_totp_enabled_at',
                'employee_totp_secret',
                'security_version',
                'invite_token_hash',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
