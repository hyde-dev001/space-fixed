<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('shop_owners');

        Schema::table('shop_owners', function (Blueprint $table) use ($columns): void {
            if (! in_array('shop_owner_totp_secret', $columns, true)) {
                $table->text('shop_owner_totp_secret')->nullable()->after('two_factor_email_enabled');
            }

            if (! in_array('shop_owner_totp_enabled_at', $columns, true)) {
                $table->timestamp('shop_owner_totp_enabled_at')->nullable()->after('shop_owner_totp_secret');
            }

            if (! in_array('shop_owner_totp_recovery_codes', $columns, true)) {
                $table->text('shop_owner_totp_recovery_codes')->nullable()->after('shop_owner_totp_enabled_at');
            }

            if (! in_array('shop_owner_totp_last_used_timestep', $columns, true)) {
                $table->unsignedBigInteger('shop_owner_totp_last_used_timestep')->nullable()->after('shop_owner_totp_recovery_codes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table): void {
            foreach ([
                'shop_owner_totp_last_used_timestep',
                'shop_owner_totp_recovery_codes',
                'shop_owner_totp_enabled_at',
                'shop_owner_totp_secret',
            ] as $column) {
                if (Schema::hasColumn('shop_owners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
