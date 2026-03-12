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
        Schema::create('procurement_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->decimal('auto_pr_approval_threshold', 12, 2)->default(10000.00);
            $table->boolean('require_finance_approval')->default(true);
            $table->string('default_payment_terms')->default('Net 30');
            $table->boolean('auto_generate_po')->default(false);
            $table->text('notification_emails')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
            
            $table->unique('shop_owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procurement_settings');
    }
};
