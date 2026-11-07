<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_remittance_supplements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cod_remittance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->decimal('submitted_amount', 18, 2);
            $table->decimal('received_amount', 18, 2)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamps();

            $table->unique(['cod_remittance_id', 'idempotency_key'], 'cod_remittance_supplements_remittance_key_unique');
            $table->index(['cod_remittance_id', 'confirmed_at'], 'cod_remittance_supplements_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_remittance_supplements');
    }
};
