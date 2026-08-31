<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'terminated_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->timestamp('terminated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'terminated_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('terminated_at');
            });
        }
    }
};
