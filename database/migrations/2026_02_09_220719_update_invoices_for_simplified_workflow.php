<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table) {
            // Add payment tracking fields
            $table->date('payment_date')->nullable()->after('status');
            $table->string('payment_method')->nullable()->after('payment_date');
            
            // Update status enum to remove 'posted'
            // Note: This modifies the existing enum
            DB::statement("ALTER TABLE finance_invoices MODIFY COLUMN status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled') DEFAULT 'draft'");
            
            // Drop journal_entry_id foreign key and column
            if (Schema::hasColumn('finance_invoices', 'journal_entry_id')) {
                $table->dropForeign(['journal_entry_id']);
                $table->dropColumn('journal_entry_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table) {
            // Remove payment tracking fields
            $table->dropColumn(['payment_date', 'payment_method']);
            
            // Restore journal_entry_id
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
            $table->foreign('journal_entry_id')->references('id')->on('finance_journal_entries')->onDelete('set null');
            
            // Restore 'posted' status
            DB::statement("ALTER TABLE finance_invoices MODIFY COLUMN status ENUM('draft', 'sent', 'posted', 'paid', 'overdue', 'cancelled') DEFAULT 'draft'");
        });
    }
};
