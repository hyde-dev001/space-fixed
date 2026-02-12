<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->string('name', 100);
            $table->enum('cycle_type', ['annual', 'quarterly', 'monthly']);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('self_review_deadline');
            $table->date('manager_review_deadline');
            $table->enum('status', ['draft', 'active', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->text('description')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shop_owner_id');
            $table->index(['shop_owner_id', 'status']);
            $table->index(['shop_owner_id', 'cycle_type']);
            $table->index('start_date');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_performance_cycles');
    }
};
