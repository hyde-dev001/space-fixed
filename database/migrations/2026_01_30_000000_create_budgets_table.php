<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->string('category');
            $table->decimal('budgeted', 18, 2);
            $table->decimal('spent', 18, 2)->default(0);
            $table->string('trend')->default('stable'); // up, down, stable
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('cascade');
            $table->index('shop_owner_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('budgets');
    }
};
