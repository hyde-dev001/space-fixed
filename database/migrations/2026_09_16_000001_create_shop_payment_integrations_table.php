<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_payment_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('purpose', 64);
            $table->string('environment', 16)->default('test');
            $table->text('secret_key')->nullable();
            $table->text('webhook_callback_token')->nullable();
            $table->string('status', 32)->default('disconnected');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'provider', 'purpose'], 'shop_payment_integrations_shop_provider_purpose_unique');
            $table->index(['shop_owner_id', 'provider', 'purpose', 'status'], 'shop_payment_integrations_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_payment_integrations');
    }
};
