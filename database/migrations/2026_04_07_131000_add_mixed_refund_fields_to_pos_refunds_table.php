<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            $table->string('preferred_return_channel', 30)->nullable()->after('evidence_snapshot');
            $table->string('preferred_return_account_name')->nullable()->after('preferred_return_channel');
            $table->string('preferred_return_account_ref')->nullable()->after('preferred_return_account_name');
            $table->boolean('customer_payout_consent')->default(false)->after('preferred_return_account_ref');

            $table->string('execution_channel', 30)->nullable()->after('execution_mode');
            $table->string('execution_reference', 150)->nullable()->after('execution_channel');
            $table->decimal('execution_amount', 12, 2)->nullable()->after('execution_reference');
            $table->json('execution_proof_urls')->nullable()->after('execution_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_return_channel',
                'preferred_return_account_name',
                'preferred_return_account_ref',
                'customer_payout_consent',
                'execution_channel',
                'execution_reference',
                'execution_amount',
                'execution_proof_urls',
            ]);
        });
    }
};
