<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_invoice_payments')) {
            Schema::create('finance_invoice_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
                $table->string('entry_type', 32);
                $table->decimal('amount', 18, 2);
                $table->string('payment_method', 64);
                $table->string('reference')->nullable();
                $table->dateTime('received_at');
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key')->nullable();
                $table->foreignId('reverses_payment_id')->nullable()
                    ->constrained('finance_invoice_payments')->restrictOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->string('source', 32)->default('manual');
                $table->timestamps();

                $table->index(['shop_owner_id', 'received_at'], 'finance_invoice_payments_shop_received_index');
                $table->index(['invoice_id', 'entry_type'], 'finance_invoice_payments_invoice_entry_index');
                $table->unique(['shop_owner_id', 'idempotency_key'], 'finance_invoice_payments_shop_key_unique');
                $table->unique('reverses_payment_id', 'finance_invoice_payments_reversal_unique');
            });
        }

        if (! Schema::hasTable('finance_expense_settlements')) {
            Schema::create('finance_expense_settlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
                $table->foreignId('expense_id')->constrained('finance_expenses')->restrictOnDelete();
                $table->string('entry_type', 32);
                $table->decimal('amount', 18, 2);
                $table->string('payment_method', 64);
                $table->string('reference')->nullable();
                $table->dateTime('paid_at');
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key')->nullable();
                $table->foreignId('reverses_settlement_id')->nullable()
                    ->constrained('finance_expense_settlements')->restrictOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->string('source', 32)->default('manual');
                $table->string('source_reference')->nullable();
                $table->timestamps();

                $table->index(['shop_owner_id', 'paid_at'], 'finance_expense_settlements_shop_paid_index');
                $table->index(['expense_id', 'entry_type'], 'finance_expense_settlements_expense_entry_index');
                $table->unique(['shop_owner_id', 'idempotency_key'], 'finance_expense_settlements_shop_key_unique');
                $table->unique('reverses_settlement_id', 'finance_expense_settlements_reversal_unique');
                $table->unique(['source', 'source_reference'], 'finance_expense_settlements_source_reference_unique');
            });
        }

        if (Schema::hasTable('finance_expenses') && ! Schema::hasColumn('finance_expenses', 'due_date')) {
            Schema::table('finance_expenses', function (Blueprint $table): void {
                $table->date('due_date')->nullable()->after('date');
                $table->index(['shop_id', 'due_date'], 'finance_expenses_shop_due_date_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_expenses') && Schema::hasColumn('finance_expenses', 'due_date')) {
            Schema::table('finance_expenses', function (Blueprint $table): void {
                $table->dropIndex('finance_expenses_shop_due_date_index');
                $table->dropColumn('due_date');
            });
        }

        Schema::dropIfExists('finance_expense_settlements');
        Schema::dropIfExists('finance_invoice_payments');
    }
};
