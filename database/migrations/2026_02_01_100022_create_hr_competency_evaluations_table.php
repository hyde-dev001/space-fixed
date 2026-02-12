<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_competency_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('review_id')->constrained('performance_reviews')->onDelete('cascade');
            $table->foreignId('cycle_id')->nullable()->constrained('hr_performance_cycles')->onDelete('set null');
            $table->string('competency_name', 100);
            $table->text('competency_description')->nullable();
            $table->integer('self_rating')->nullable()->comment('Employee self-rating 1-5');
            $table->integer('manager_rating')->nullable()->comment('Manager rating 1-5');
            $table->integer('calibrated_rating')->nullable()->comment('Final calibrated rating 1-5');
            $table->text('self_comments')->nullable();
            $table->text('manager_comments')->nullable();
            $table->decimal('weight', 5, 2)->default(0.00)->comment('Competency weight in percentage');
            $table->timestamps();

            // Indexes
            $table->index('shop_owner_id');
            $table->index('review_id');
            $table->index('cycle_id');
            $table->index(['review_id', 'competency_name']);
            $table->index(['shop_owner_id', 'competency_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_competency_evaluations');
    }
};
