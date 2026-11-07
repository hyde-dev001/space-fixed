<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_profiles', function (Blueprint $table): void {
            $table->string('wallet_provider', 120)->nullable()->after('destination_type');
            $table->text('account_identifier')->nullable()->after('account_name');
            $table->string('bank_name', 120)->nullable()->change();
            $table->string('bank_code', 64)->nullable()->change();
            $table->text('account_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_profiles', function (Blueprint $table): void {
            $table->dropColumn(['wallet_provider', 'account_identifier']);
            $table->string('bank_name', 120)->nullable(false)->change();
            $table->string('bank_code', 64)->nullable(false)->change();
            $table->text('account_number')->nullable(false)->change();
        });
    }
};
