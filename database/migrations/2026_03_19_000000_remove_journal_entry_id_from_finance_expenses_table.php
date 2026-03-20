<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Drop the journal_entry_id column
        Schema::table('finance_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('finance_expenses', 'journal_entry_id')) {
                $table->dropColumn('journal_entry_id');
            }
        });

        // Update status enum to remove 'posted'
        // Change any 'posted' statuses to 'approved'
        DB::table('finance_expenses')
            ->where('status', 'posted')
            ->update(['status' => 'approved']);

        // Modify the enum column
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->string('status')->change(); // Change to string to allow modification
        });

        DB::statement("ALTER TABLE finance_expenses MODIFY status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'submitted'");
    }

    public function down(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
        });

        // Restore enum
        DB::statement("ALTER TABLE finance_expenses MODIFY status ENUM('draft', 'submitted', 'approved', 'posted', 'rejected') DEFAULT 'submitted'");
    }
};
