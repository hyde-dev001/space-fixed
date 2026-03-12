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
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->string('code')->unique(); // "CC001", "DEPT-SALES"
            $table->string('name'); // "Sales Department", "Marketing"
            $table->text('description')->nullable();
            $table->enum('type', ['department', 'project', 'location', 'division'])->default('department');
            $table->unsignedBigInteger('parent_id')->nullable(); // For hierarchical cost centers
            $table->boolean('is_active')->default(true);
            $table->decimal('budget_limit', 18, 2)->nullable();
            $table->string('manager_name')->nullable();
            $table->string('manager_email')->nullable();
            $table->timestamps();

            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('cost_centers')->onDelete('set null');
            $table->index('shop_owner_id');
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
