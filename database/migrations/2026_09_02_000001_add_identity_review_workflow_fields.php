<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->string('rejection_reason', 96)->nullable()->after('failure_reason');
            $table->text('rejection_notes')->nullable()->after('rejection_reason');
            $table->foreignId('inspected_by')->nullable()->after('reviewed_by')->constrained('super_admins')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable()->after('reviewed_at');
            $table->foreignId('supersedes_verification_id')
                ->nullable()
                ->after('user_id')
                ->constrained('identity_verifications')
                ->nullOnDelete();

            $table->index(['review_status', 'inspected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->dropForeign(['supersedes_verification_id']);
            $table->dropForeign(['inspected_by']);
            $table->dropIndex('identity_verifications_review_status_inspected_at_index');
            $table->dropColumn([
                'rejection_reason',
                'rejection_notes',
                'inspected_by',
                'inspected_at',
                'supersedes_verification_id',
            ]);
        });
    }
};
