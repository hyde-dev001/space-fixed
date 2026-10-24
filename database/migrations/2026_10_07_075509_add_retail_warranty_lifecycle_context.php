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
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('retail_warranty_fulfilled_at')->nullable()->index();
            $table->json('retail_warranty_policy_snapshot')->nullable();
        });
        foreach (['order_refunds', 'pos_refunds'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('request_basis', 16)->default('ordinary'));
        }
        foreach (['order_refund_items', 'pos_refund_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('retail_warranty_id')->nullable()->constrained()->restrictOnDelete());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['order_refund_items', 'pos_refund_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('retail_warranty_id'));
        }
        foreach (['order_refunds', 'pos_refunds'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('request_basis'));
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['retail_warranty_fulfilled_at']);
            $table->dropColumn(['retail_warranty_fulfilled_at', 'retail_warranty_policy_snapshot']);
        });
    }
};
