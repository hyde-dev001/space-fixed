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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_half_day')->default(false)->after('no_of_days');
            $table->integer('approval_level')->default(1)->after('status');
            $table->foreignId('approver_id')->nullable()->after('approval_level')->constrained('users')->nullOnDelete();
            $table->string('supporting_document')->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['is_half_day', 'approval_level', 'approver_id', 'supporting_document']);
        });
    }
};
