<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PrivilegedLegacyWriterCutoverTest extends TestCase
{
    public function test_super_admin_controller_no_longer_contains_unreachable_legacy_registration_writers(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SuperAdminController.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use App\\Models\\AuditLog;', $source);
        $this->assertStringNotContainsString('approveShopOwner', $source);
        $this->assertStringNotContainsString('rejectShopOwner', $source);
    }

    public function test_no_route_points_to_removed_legacy_registration_handlers(): void
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            $this->assertStringNotContainsString('approveShopOwner', $action, $route->uri());
            $this->assertStringNotContainsString('rejectShopOwner', $action, $route->uri());
        }
    }
}
