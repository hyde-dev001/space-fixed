<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('users');

        Schema::table('users', function (Blueprint $table) use ($columns): void {
            if (! in_array('security_version', $columns, true)) {
                $table->unsignedInteger('security_version')->default(1)->after('force_password_change');
            }

            if (! in_array('employee_totp_secret', $columns, true)) {
                $table->text('employee_totp_secret')->nullable()->after('security_version');
            }

            if (! in_array('employee_totp_enabled_at', $columns, true)) {
                $table->timestamp('employee_totp_enabled_at')->nullable()->after('employee_totp_secret');
            }

            if (! in_array('employee_totp_recovery_codes', $columns, true)) {
                $table->text('employee_totp_recovery_codes')->nullable()->after('employee_totp_enabled_at');
            }

            if (! in_array('employee_totp_last_used_timestep', $columns, true)) {
                $table->unsignedBigInteger('employee_totp_last_used_timestep')->nullable()->after('employee_totp_recovery_codes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach ([
                'employee_totp_last_used_timestep',
                'employee_totp_recovery_codes',
                'employee_totp_enabled_at',
                'employee_totp_secret',
                'security_version',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
