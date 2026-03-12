<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('date');
            $table->string('category');
            $table->string('vendor')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'posted', 'rejected'])->default('submitted');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->unsignedBigInteger('payment_account_id')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime_type')->nullable();
            $table->integer('receipt_size')->nullable();
            $table->unsignedBigInteger('job_order_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('job_order_id')->references('id')->on('orders')->onDelete('set null');
            $table->index('job_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
