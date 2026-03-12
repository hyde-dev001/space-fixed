<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->string('requested_size', 20)->nullable()->after('quantity_needed');
        });
    }

    public function down(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->dropColumn('requested_size');
        });
    }
};
