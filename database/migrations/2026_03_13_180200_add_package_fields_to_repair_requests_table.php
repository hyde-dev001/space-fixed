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
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('repair_package_id')->nullable()->after('shop_owner_id');
            $table->decimal('package_price', 10, 2)->nullable()->after('total');
            $table->decimal('add_ons_total', 10, 2)->nullable()->after('package_price');
            $table->decimal('final_total', 10, 2)->nullable()->after('add_ons_total');
            $table->json('included_services_snapshot')->nullable()->after('final_total');
            $table->json('add_on_services_snapshot')->nullable()->after('included_services_snapshot');
            $table->json('pricing_breakdown')->nullable()->after('add_on_services_snapshot');

            $table->foreign('repair_package_id')
                ->references('id')
                ->on('repair_packages')
                ->onDelete('set null');

            $table->index('repair_package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropForeign(['repair_package_id']);
            $table->dropIndex(['repair_package_id']);

            $table->dropColumn([
                'repair_package_id',
                'package_price',
                'add_ons_total',
                'final_total',
                'included_services_snapshot',
                'add_on_services_snapshot',
                'pricing_breakdown',
            ]);
        });
    }
};