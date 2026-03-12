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
        Schema::create('expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->unsignedBigInteger('cost_center_id');
            $table->unsignedBigInteger('finance_journal_line_id'); // Links to actual expense line
            $table->decimal('amount', 18, 2);
            $table->decimal('percentage', 5, 2)->nullable(); // If split across multiple cost centers
            $table->text('notes')->nullable();
            $table->date('allocation_date');
            $table->timestamps();

            $table->foreign('shop_owner_id', 'ea_so_id_fk')->references('id')->on('shop_owners')->onDelete('cascade');
            $table->foreign('cost_center_id', 'ea_cc_id_fk')->references('id')->on('cost_centers')->onDelete('restrict');
            $table->foreign('finance_journal_line_id', 'ea_fjl_id_fk')->references('id')->on('finance_journal_lines')->onDelete('cascade');
            $table->index('shop_owner_id');
            $table->index('cost_center_id');
            $table->index('finance_journal_line_id');
            $table->index('allocation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_allocations');
    }
};
