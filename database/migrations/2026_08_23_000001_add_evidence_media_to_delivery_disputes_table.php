<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_disputes') || Schema::hasColumn('delivery_disputes', 'evidence_media')) {
            return;
        }

        Schema::table('delivery_disputes', function (Blueprint $table): void {
            $table->json('evidence_media')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_disputes') || ! Schema::hasColumn('delivery_disputes', 'evidence_media')) {
            return;
        }

        Schema::table('delivery_disputes', function (Blueprint $table): void {
            $table->dropColumn('evidence_media');
        });
    }
};
