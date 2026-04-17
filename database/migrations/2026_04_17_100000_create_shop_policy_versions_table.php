<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->string('business_type_scope', 20);
            $table->string('registration_clause_mode', 40)->default('individual_business_clause');
            $table->json('policy_sections_json');
            $table->char('content_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'version_number']);
            $table->index(['shop_owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_policy_versions');
    }
};
