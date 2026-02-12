<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('checklist_id')->constrained('hr_onboarding_checklists')->onDelete('cascade');
            $table->string('task_name', 200);
            $table->text('description')->nullable();
            $table->enum('assigned_to', ['employee', 'hr', 'manager', 'it'])->default('hr');
            $table->integer('due_days')->default(7)->comment('Days after hire date');
            $table->boolean('is_mandatory')->default(true);
            $table->integer('order')->default(0)->comment('Task display order');
            $table->timestamps();

            // Indexes
            $table->index('shop_owner_id');
            $table->index('checklist_id');
            $table->index(['checklist_id', 'order']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_tasks');
    }
};
