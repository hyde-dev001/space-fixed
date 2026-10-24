<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('finance_expenses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_payment_profile_id')
                ->constrained('supplier_payment_profiles')
                ->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('provider', 64)->default('paymongo');
            $table->string('internal_reference', 100)->unique();
            $table->string('provider_reference', 160)->nullable()->unique();
            $table->string('idempotency_key', 100);
            $table->longText('destination_snapshot');
            $table->string('status', 20)->default('initiating');
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settlement_id')->nullable()
                ->constrained('finance_expense_settlements')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'idempotency_key'], 'supplier_payment_attempts_shop_key_unique');
            $table->unique('settlement_id', 'supplier_payment_attempts_settlement_unique');
            $table->index(['shop_owner_id', 'status'], 'supplier_payment_attempts_shop_status_index');
            $table->index(['expense_id', 'status'], 'supplier_payment_attempts_expense_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_attempts');
    }
};
