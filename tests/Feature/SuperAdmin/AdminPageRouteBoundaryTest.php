<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class AdminPageRouteBoundaryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    #[DataProvider('restrictedPageRoutes')]
    public function test_regular_admin_cannot_open_unassigned_page_routes(string $uri): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get($uri)
            ->assertForbidden();
    }

    public function test_regular_admin_cannot_call_an_unassigned_page_action(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson('/admin/platform-fees/settings', [])
            ->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function restrictedPageRoutes(): array
    {
        return [
            'platform fees' => ['/admin/platform-fees'],
            'maintenance' => ['/admin/maintenance'],
            'administrators' => ['/admin/administrators'],
            'registered shops' => ['/admin/shops'],
            'user management' => ['/admin/users'],
            'shop reports' => ['/admin/shop-reports'],
            'suspension appeals' => ['/admin/appeals'],
            'subscriptions' => ['/admin/subscriptions'],
            'audit history' => ['/admin/audit'],
        ];
    }
}
