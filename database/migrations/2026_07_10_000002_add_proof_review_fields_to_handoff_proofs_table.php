<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('handoff_proofs', function (Blueprint $table) {
            $table->string('review_status')->default('pending')->after('metadata');
            $table->string('reviewed_by_type')->nullable()->after('review_status');
            $table->unsignedBigInteger('reviewed_by_id')->nullable()->after('reviewed_by_type');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('handoff_proofs', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'reviewed_by_type', 'reviewed_by_id', 'reviewed_at', 'rejection_reason']);
        });
    }
};
