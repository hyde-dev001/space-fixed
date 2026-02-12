<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('finance_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->index();
            $table->date('date');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->string('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('finance_journal_entries');
    }
};
