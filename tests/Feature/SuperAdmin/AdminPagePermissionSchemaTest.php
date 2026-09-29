<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\AdminPage;
use App\Models\AdminPagePermission;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminPagePermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_permission_table_uses_the_privileged_identity_and_unique_page_key_pair(): void
    {
        self::assertTrue(Schema::hasTable('admin_page_permissions'));
        self::assertTrue(Schema::hasColumns('admin_page_permissions', [
            'id',
            'super_admin_id',
            'page_key',
            'created_at',
            'updated_at',
        ]));

        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::create([
            'super_admin_id' => $admin->id,
            'page_key' => AdminPage::PLATFORM_FEES->value,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AdminPagePermission::create([
            'super_admin_id' => $admin->id,
            'page_key' => AdminPage::PLATFORM_FEES->value,
        ]);
    }

    public function test_catalog_contains_only_delegable_pages_for_regular_admins(): void
    {
        self::assertSame([
            'dashboard',
            'user_management',
            'shop_management',
            'document_renewals',
            'business_upgrade_requests',
            'shop_reports',
            'suspension_appeals',
            'platform_fees',
            'audit_history',
            'registered_shops',
            'subscription_management',
        ], AdminPage::assignableKeys());

        self::assertFalse(AdminPage::isAssignable('admin_management'));
        self::assertFalse(AdminPage::isAssignable('system_maintenance'));
    }
}
