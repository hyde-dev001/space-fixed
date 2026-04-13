<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_warranty_claims', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_warranty_claims', 'preferred_receive_method')) {
                $table->string('preferred_receive_method', 32)
                    ->nullable()
                    ->after('preferred_return_method')
                    ->comment('How customer receives reworked shoes: walk_in | shop_delivery');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_warranty_claims', function (Blueprint $table) {
            if (Schema::hasColumn('repair_warranty_claims', 'preferred_receive_method')) {
                $table->dropColumn('preferred_receive_method');
            }
        });
    }
};
