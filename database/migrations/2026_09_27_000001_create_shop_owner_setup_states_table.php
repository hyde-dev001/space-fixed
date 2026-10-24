<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_owner_setup_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->unique()->constrained('shop_owners')->cascadeOnDelete();
            $table->timestamp('welcome_seen_at')->nullable();
            $table->json('tutorials')->nullable();
            $table->timestamps();
        });

        // Existing owners retain access to the guide without receiving a new-account welcome.
        DB::table('shop_owners')->select('id')->orderBy('id')->chunkById(500, function ($owners): void {
            $now = now();
            DB::table('shop_owner_setup_states')->insert($owners->map(fn ($owner): array => [
                'shop_owner_id' => $owner->id,
                'welcome_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_owner_setup_states');
    }
};
