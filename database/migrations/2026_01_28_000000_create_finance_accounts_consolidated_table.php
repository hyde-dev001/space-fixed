<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create finance_accounts table with all fields consolidated
     * 
     * Previously scattered across:
     * - 2026_01_28_000000 (duplicate v1)
     * - 2026_01_28_000001 (duplicate v2)  
     * - 2026_01_28_000010 (add_balance)
     * - 2026_01_30_000000 (add_shop_owner_id)
     */
    public function up()
    {
        if (!Schema::hasTable('finance_accounts')) {
            Schema::create('finance_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('type'); // Asset, Liability, Equity, Revenue, Expense
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('normal_balance')->default('Debit');
                $table->string('group')->nullable();
                $table->decimal('balance', 18, 2)->default(0);
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('shop_owner_id')->nullable();
                $table->unsignedBigInteger('shop_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('parent_id')->references('id')->on('finance_accounts')->onDelete('set null');
                $table->index('shop_owner_id');
                $table->index('shop_id');
                $table->index('code');
                $table->index('type');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('finance_accounts')) {
            Schema::table('finance_accounts', function (Blueprint $table) {
                $table->dropForeign(['parent_id']);
            });
            Schema::dropIfExists('finance_accounts');
        }
    }
};
