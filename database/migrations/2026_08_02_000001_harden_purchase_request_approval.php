<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending_finance', 'pending_shop_owner', 'pending_finance_final', 'approved', 'rejected'])
                ->default('draft')->change();
            $table->foreignId('approved_by_shop_owner_id')->nullable()->after('reviewed_date')
                ->constrained('shop_owners')->nullOnDelete();
            $table->timestamp('shop_owner_approved_at')->nullable()->after('approved_by_shop_owner_id');
            $table->foreignId('rejected_by_user_id')->nullable()->after('approved_date')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_shop_owner_id')->nullable()->after('rejected_by_user_id')
                ->constrained('shop_owners')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_shop_owner_id');
        });
    }

    public function down(): void
    {
        DB::table('purchase_requests')
            ->where('status', 'pending_finance_final')
            ->update(['status' => 'pending_shop_owner']);

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending_finance', 'pending_shop_owner', 'approved', 'rejected'])
                ->default('draft')->change();
            $table->dropConstrainedForeignId('approved_by_shop_owner_id');
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropConstrainedForeignId('rejected_by_shop_owner_id');
            $table->dropColumn(['shop_owner_approved_at', 'rejected_at']);
        });
    }
};
