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
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->enum('request_source', ['manual', 'repair'])->default('manual')->after('priority');
            $table->foreignId('repair_request_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('repair_requests')
                ->onDelete('set null');

            $table->index('request_source');
            $table->index('repair_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->dropIndex(['request_source']);
            $table->dropIndex(['repair_request_id']);
            $table->dropConstrainedForeignId('repair_request_id');
            $table->dropColumn('request_source');
        });
    }
};
