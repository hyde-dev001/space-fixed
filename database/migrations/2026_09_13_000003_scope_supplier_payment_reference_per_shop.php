<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('supplier_payment_attempts_provider_reference_unique');
            $table->unique(
                ['shop_owner_id', 'provider_reference'],
                'supplier_payment_attempts_shop_provider_reference_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('supplier_payment_attempts_shop_provider_reference_unique');
            $table->unique('provider_reference', 'supplier_payment_attempts_provider_reference_unique');
        });
    }
};
