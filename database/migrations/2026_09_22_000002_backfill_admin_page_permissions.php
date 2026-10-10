<?php

use App\Enums\AdminPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pageRows = [];
        $timestamp = now();

        DB::table('super_admins')
            ->where('role', 'admin')
            ->pluck('id')
            ->each(function (int|string $adminId) use (&$pageRows, $timestamp): void {
                foreach (AdminPage::assignableKeys() as $pageKey) {
                    $pageRows[] = [
                        'super_admin_id' => (int) $adminId,
                        'page_key' => $pageKey,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            });

        foreach (array_chunk($pageRows, 500) as $chunk) {
            DB::table('admin_page_permissions')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('admin_page_permissions')
            ->whereIn('page_key', AdminPage::assignableKeys())
            ->whereIn('super_admin_id', DB::table('super_admins')->where('role', 'admin')->pluck('id'))
            ->delete();
    }
};
