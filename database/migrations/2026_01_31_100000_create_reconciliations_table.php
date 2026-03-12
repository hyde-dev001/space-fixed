<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('journal_entry_line_id');
            $table->string('bank_transaction_reference')->nullable();
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->unsignedBigInteger('reconciled_by');
            $table->timestamp('reconciled_at')->nullable();
            $table->enum('status', ['pending', 'matched', 'reconciled', 'discrepancy'])->default('pending');
            $table->unsignedBigInteger('shop_owner_id');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('account_id')->references('id')->on('finance_accounts')->onDelete('cascade');
            $table->foreign('journal_entry_line_id')->references('id')->on('finance_journal_lines')->onDelete('cascade');
            $table->foreign('reconciled_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('shop_owner_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes for performance
            $table->index('account_id');
            $table->index('statement_date');
            $table->index('status');
            $table->index('shop_owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
