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
        Schema::create('recurring_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recurring_transaction_id');
            $table->unsignedBigInteger('chart_of_account_id');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('cost_center')->nullable();
            $table->integer('line_number'); // 1, 2, 3...
            $table->timestamps();

            $table->foreign('recurring_transaction_id', 'rt_lines_rt_id_fk')
                ->references('id')
                ->on('recurring_transactions')
                ->onDelete('cascade');
            $table->foreign('chart_of_account_id', 'rt_lines_coa_id_fk')
                ->references('id')
                ->on('finance_accounts')
                ->onDelete('restrict');
            $table->index('recurring_transaction_id');
            $table->index('chart_of_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transaction_lines');
    }
};
