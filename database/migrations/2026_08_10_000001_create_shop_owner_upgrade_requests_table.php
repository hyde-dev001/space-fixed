<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_owner_upgrade_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('current_registration_type', 32);
            $table->string('current_business_type', 32);
            $table->string('requested_registration_type', 32);
            $table->string('requested_business_type', 32);
            $table->string('status', 32);
            $table->json('required_document_set');
            $table->text('decision_reason')->nullable();
            $table->foreignId('reviewed_by_super_admin_id')
                ->nullable()
                ->constrained('super_admins')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('shop_owner_id');
            $table->index(['shop_owner_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_owner_upgrade_requests');
    }
};
