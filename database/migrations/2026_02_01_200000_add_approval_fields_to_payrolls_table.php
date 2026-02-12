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
        Schema::table('payrolls', function (Blueprint $table) {
            // Finance approval workflow fields
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])
                  ->default('pending')
                  ->after('status')
                  ->comment('Finance approval status');
            
            $table->foreignId('approved_by')
                  ->nullable()
                  ->after('approval_status')
                  ->constrained('users')
                  ->onDelete('set null')
                  ->comment('Finance user who approved/rejected');
            
            $table->timestamp('approved_at')
                  ->nullable()
                  ->after('approved_by')
                  ->comment('When the payslip was approved/rejected');
            
            $table->text('approval_notes')
                  ->nullable()
                  ->after('approved_at')
                  ->comment('Approval or rejection notes from Finance');
            
            // Add index for filtering by approval status
            $table->index('approval_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn([
                'approval_status',
                'approved_by',
                'approved_at',
                'approval_notes'
            ]);
        });
    }
};
