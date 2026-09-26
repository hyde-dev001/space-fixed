<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_receipt_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')
                ->unique()
                ->constrained('shop_owners')
                ->restrictOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        DB::table('shop_owners')->select('id')->orderBy('id')->each(function (object $shop): void {
            DB::table('shop_receipt_sequences')->insertOrIgnore([
                'shop_owner_id' => $shop->id,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_receipt_sequences');
    }
};
