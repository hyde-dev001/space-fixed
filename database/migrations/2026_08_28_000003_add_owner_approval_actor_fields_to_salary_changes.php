<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_changes', function (Blueprint $table): void {
            if (! Schema::hasColumn('salary_changes', 'approved_by_shop_owner_id')) {
                $table->foreignId('approved_by_shop_owner_id')
                    ->nullable()
                    ->after('approved_by')
                    ->constrained('shop_owners')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('salary_changes', 'rejected_by_shop_owner_id')) {
                $table->foreignId('rejected_by_shop_owner_id')
                    ->nullable()
                    ->after('rejected_by')
                    ->constrained('shop_owners')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('salary_changes', function (Blueprint $table): void {
            if (Schema::hasColumn('salary_changes', 'approved_by_shop_owner_id')) {
                $table->dropConstrainedForeignId('approved_by_shop_owner_id');
            }

            if (Schema::hasColumn('salary_changes', 'rejected_by_shop_owner_id')) {
                $table->dropConstrainedForeignId('rejected_by_shop_owner_id');
            }
        });
    }
};
