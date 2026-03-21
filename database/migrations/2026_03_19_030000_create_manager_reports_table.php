<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->string('report_type', 50);
            $table->string('date_range', 20);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->enum('status', ['generated', 'sent'])->default('generated');
            $table->text('notes')->nullable();
            $table->json('report_data')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'report_type']);
            $table->index(['shop_owner_id', 'status']);
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_reports');
    }
};
