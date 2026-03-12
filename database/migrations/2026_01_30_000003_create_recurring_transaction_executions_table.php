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
        Schema::create('recurring_transaction_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recurring_transaction_id');
            $table->unsignedBigInteger('finance_journal_entry_id')->nullable(); // Links to actual posted journal entry
            $table->date('execution_date');
            $table->enum('status', ['pending', 'executed', 'skipped', 'failed'])->default('pending');
            $table->text('notes')->nullable();
            $table->string('executed_by')->nullable(); // User who executed
            $table->timestamps();

            $table->foreign('recurring_transaction_id', 'rt_exec_rt_id_fk')
                ->references('id')
                ->on('recurring_transactions')
                ->onDelete('cascade');
            $table->foreign('finance_journal_entry_id', 'rt_exec_fje_id_fk')
                ->references('id')
                ->on('finance_journal_entries')
                ->onDelete('set null');
            $table->index('recurring_transaction_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transaction_executions');
    }
};
