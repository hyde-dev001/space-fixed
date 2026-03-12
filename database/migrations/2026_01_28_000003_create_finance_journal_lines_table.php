<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('finance_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id')->index();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('account_code')->nullable();
            $table->string('account_name')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo')->nullable();
            $table->string('tax')->nullable();
            $table->timestamps();

            $table->foreign('journal_entry_id')->references('id')->on('finance_journal_entries')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('finance_accounts')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('finance_journal_lines');
    }
};
