<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('stock_request_id')->nullable()->unique()->after('shop_owner_id')
                ->constrained('stock_request_approvals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropUnique(['stock_request_id']);
            $table->dropConstrainedForeignId('stock_request_id');
        });
    }
};
