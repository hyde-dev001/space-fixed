<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PrivilegedLegacyWriterCutoverTest extends TestCase
{
    public function test_retired_super_admin_controller_is_not_a_runtime_owner(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/SuperAdminController.php'));
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
