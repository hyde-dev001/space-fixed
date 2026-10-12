<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_fee_settings', function (Blueprint $table): void {
            $table->string('terms_version', 80)->nullable()->after('enforcement_enabled');
            $table->text('terms_text')->nullable()->after('terms_version');
        });

        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->string('platform_fee_terms_version', 80)->nullable()->after('registration_type');
            $table->unsignedBigInteger('platform_fee_terms_accepted_by')->nullable()->after('platform_fee_terms_version');
            $table->timestamp('platform_fee_terms_accepted_at')->nullable()->after('platform_fee_terms_accepted_by');
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->dropColumn([
                'platform_fee_terms_version',
                'platform_fee_terms_accepted_by',
                'platform_fee_terms_accepted_at',
            ]);
        });

        Schema::table('platform_fee_settings', function (Blueprint $table): void {
            $table->dropColumn(['terms_version', 'terms_text']);
        });
    }
};
