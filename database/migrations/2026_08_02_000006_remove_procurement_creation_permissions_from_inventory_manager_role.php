<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'procurement.create_purchase_requests',
        'procurement.submit_purchase_requests',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'Inventory Manager')
            ->where('guard_name', 'user')
            ->value('id');

        if ($roleId) {
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', DB::table('permissions')
                    ->select('id')
                    ->where('guard_name', 'user')
                    ->whereIn('name', self::PERMISSIONS))
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'Inventory Manager')
            ->where('guard_name', 'user')
            ->value('id');

        if ($roleId) {
            $rows = DB::table('permissions')
                ->where('guard_name', 'user')
                ->whereIn('name', self::PERMISSIONS)
                ->pluck('id')
                ->map(fn ($permissionId) => [
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ])
                ->all();

            if ($rows) {
                DB::table('role_has_permissions')->insertOrIgnore($rows);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
