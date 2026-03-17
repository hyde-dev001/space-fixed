<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('final_approved_by')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('final_approved_at')->nullable()->after('final_approved_by');
            $table->text('final_approval_notes')->nullable()->after('final_approved_at');

            $table->string('payout_reference')->nullable()->after('payment_method');
            $table->string('payout_proof_type')->nullable()->after('payout_reference');
            $table->string('payout_proof_reference')->nullable()->after('payout_proof_type');
            $table->text('payout_proof_notes')->nullable()->after('payout_proof_reference');
            $table->foreignId('disbursed_by')
                ->nullable()
                ->after('payout_proof_notes')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable()->after('disbursed_by');

            $table->index('final_approved_by');
            $table->index('disbursed_by');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payrolls MODIFY status ENUM('pending', 'processed', 'approved', 'paid') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        DB::table('payrolls')
            ->where('status', 'approved')
            ->update(['status' => 'processed']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payrolls MODIFY status ENUM('pending', 'processed', 'paid') DEFAULT 'pending'");
        }

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex(['final_approved_by']);
            $table->dropIndex(['disbursed_by']);
            $table->dropConstrainedForeignId('final_approved_by');
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropColumn([
                'final_approved_at',
                'final_approval_notes',
                'payout_reference',
                'payout_proof_type',
                'payout_proof_reference',
                'payout_proof_notes',
                'disbursed_at',
            ]);
        });
    }
};
