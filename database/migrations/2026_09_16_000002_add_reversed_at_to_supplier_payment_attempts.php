<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_attempts', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('reversed_at');
        });
    }
};
