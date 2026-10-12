<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('destination_type', 32);
            $table->string('bank_name', 120);
            $table->string('bank_code', 64);
            $table->string('account_name', 160);
            $table->text('account_number');
            $table->string('status', 20)->default('unverified');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique('supplier_id', 'supplier_payment_profiles_supplier_unique');
            $table->index(['shop_owner_id', 'status'], 'supplier_payment_profiles_shop_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_profiles');
    }
};
