<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const EXPANDED_STATUSES = ['active', 'inactive', 'on_leave', 'suspended', 'terminated'];

    /** @var list<string> */
    private const LEGACY_COMPATIBLE_STATUSES = ['active', 'inactive', 'on_leave', 'suspended'];

    public function up(): void
    {
        if ($this->driver() !== 'mysql') {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->enum('status', self::EXPANDED_STATUSES)
                ->default('active')
                ->change();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->where('status', 'terminated')->exists()) {
            throw new RuntimeException(
                'Cannot remove terminated from employees.status while terminated employees exist.',
            );
        }

        if ($this->driver() !== 'mysql') {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->enum('status', self::LEGACY_COMPATIBLE_STATUSES)
                ->default('active')
                ->change();
        });
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }
};
