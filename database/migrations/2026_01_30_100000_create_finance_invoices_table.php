<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('finance_invoices')) {
            Schema::create('finance_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name');
                $table->string('customer_email')->nullable();
                $table->date('date');
                $table->date('due_date')->nullable();
                $table->decimal('total', 18, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->enum('status', ['draft', 'sent', 'posted', 'paid', 'overdue', 'cancelled'])->default('draft');
                $table->unsignedBigInteger('journal_entry_id')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('job_order_id')->nullable();
                $table->json('meta')->nullable();
                $table->unsignedBigInteger('shop_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('journal_entry_id')->references('id')->on('finance_journal_entries')->onDelete('set null');
                $table->foreign('job_order_id')->references('id')->on('orders')->onDelete('set null');
                $table->index('job_order_id');
                $table->index('reference');
                $table->index('status');
                $table->index('date');
            });
        }

        if (!Schema::hasTable('finance_invoice_items')) {
            Schema::create('finance_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id');
                $table->string('description');
                $table->decimal('quantity', 10, 2);
                $table->decimal('unit_price', 18, 2);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('amount', 18, 2);
                $table->unsignedBigInteger('account_id')->nullable();
                $table->timestamps();

                $table->foreign('invoice_id')->references('id')->on('finance_invoices')->onDelete('cascade');
                $table->foreign('account_id')->references('id')->on('finance_accounts')->onDelete('set null');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('finance_invoice_items');
        Schema::dropIfExists('finance_invoices');
    }
};
