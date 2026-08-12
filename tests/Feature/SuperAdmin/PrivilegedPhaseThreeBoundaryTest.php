<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Http\Middleware\AttachPrivilegedCorrelationId;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class PrivilegedPhaseThreeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', AttachPrivilegedCorrelationId::class])
            ->get('/__tests/phase-three-correlation', function (Request $request) {
                $admin = SuperAdmin::factory()->admin()->create();
                $shopOwner = ShopOwner::factory()->create();
                $firstDocument = ShopDocument::create([
                    'shop_owner_id' => $shopOwner->id,
                    'document_type' => 'mayors_permit',
                    'file_path' => 'shop_documents/phase-three-first.pdf',
                    'status' => 'pending',
                ]);
                $secondDocument = ShopDocument::create([
                    'shop_owner_id' => $shopOwner->id,
                    'document_type' => 'bir_certificate',
                    'file_path' => 'shop_documents/phase-three-second.pdf',
                    'status' => 'pending',
                ]);

                $audit = app(PrivilegedAudit::class);
                $audit->documentAccessInitiated(
                    $request,
                    $admin,
                    $firstDocument,
                    $shopOwner,
                    'application/pdf',
                    'inline',
                );
                $audit->documentAccessInitiated(
                    $request,
                    $admin,
                    $secondDocument,
                    $shopOwner,
                    'application/pdf',
                    'inline',
                );

                return response()->json([
                    'correlation_id' => $audit->correlationId($request),
                ]);
            });
    }

    public function test_privileged_responses_use_a_server_generated_correlation_id(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'client-controlled-id')
            ->get('/admin');

        $response->assertRedirect('/admin/login');

        $correlationId = $response->headers->get('X-Correlation-ID');

        $this->assertIsString($correlationId);
        $this->assertTrue(Str::isUuid($correlationId));
        $this->assertNotSame('client-controlled-id', $correlationId);
    }

    public function test_audit_rows_written_during_one_request_reuse_the_response_correlation_id(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'client-controlled-id')
            ->getJson('/__tests/phase-three-correlation');

        $response->assertOk();

        $correlationId = $response->json('correlation_id');

        $this->assertTrue(Str::isUuid($correlationId));
        $this->assertSame($correlationId, $response->headers->get('X-Correlation-ID'));
        $this->assertNotSame('client-controlled-id', $correlationId);

        $auditCorrelationIds = Activity::query()
            ->where('log_name', 'privileged')
            ->latest('id')
            ->limit(2)
            ->get()
            ->map(fn (Activity $activity): mixed => $activity->properties['correlation_id'])
            ->values();

        $this->assertCount(2, $auditCorrelationIds);
        $this->assertSame([$correlationId], $auditCorrelationIds->unique()->all());
    }

    public function test_canonical_phase_three_mutations_keep_the_privileged_boundary(): void
    {
        $routes = [
            'admin.shop-owner-approve' => 'review_registrations',
            'admin.shop-owner-reject' => 'review_registrations',
            'admin.business-upgrade-requests.update' => 'review_registrations',
            'admin.users.suspend' => 'intervene_accounts',
            'admin.users.activate' => 'intervene_accounts',
            'admin.users.reactivate' => 'intervene_accounts',
            'admin.shops.suspend' => 'intervene_accounts',
            'admin.shops.activate' => 'intervene_accounts',
            'admin.shops.reactivate' => 'intervene_accounts',
            'admin.shop-reports.action' => 'moderate_reports',
            'admin.appeals.approve' => 'resolve_appeals',
            'admin.appeals.reject' => 'resolve_appeals',
            'admin.premium-plans.store' => 'manage_plans',
            'admin.premium-plans.update' => 'manage_plans',
            'admin.premium-plans.archive' => 'manage_plans',
            'admin.premium-plans.reactivate' => 'manage_plans',
        ];

        foreach ($routes as $name => $capability) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains(AttachPrivilegedCorrelationId::class, $route->middleware(), $name);
            $this->assertContains('super_admin.auth', $route->middleware(), $name);
            $this->assertContains('privileged.active', $route->middleware(), $name);
            $this->assertContains('privileged.mfa', $route->middleware(), $name);
            $this->assertContains("privileged.capability:{$capability}", $route->middleware(), $name);
        }
    }

    public function test_privileged_login_setup_mfa_and_compatibility_routes_have_correlation_middleware(): void
    {
        $allowlisted = [
            'admin.login',
            'admin.login.post',
            'admin.logout',
            'admin.password.request',
            'admin.password.email',
            'admin.password.reset',
            'admin.password.reset.exchange',
            'admin.password.reset.complete',
            'admin.setup',
            'admin.setup.exchange',
            'admin.setup.complete',
            'admin.mfa.challenge',
            'admin.mfa.challenge.verify',
            'admin.mfa.setup',
            'admin.mfa.setup.verify',
            'admin.mfa.setup.recovery.acknowledge',
        ];

        foreach ($allowlisted as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains(AttachPrivilegedCorrelationId::class, $route->middleware(), $name);
        }

        $privilegedRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route): bool {
                $uri = ltrim($route->uri(), '/');

                return str_starts_with($uri, 'admin/')
                    || str_starts_with($uri, 'superAdmin/')
                    || str_starts_with($uri, 'api/admin/notifications');
            });

        $this->assertNotEmpty($privilegedRoutes);
        foreach ($privilegedRoutes as $route) {
            $this->assertContains(AttachPrivilegedCorrelationId::class, $route->middleware(), $route->uri());
        }
    }

    public function test_no_route_points_to_legacy_registration_handlers(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            $this->assertStringNotContainsString('approveShopOwner', $action, $route->uri());
            $this->assertStringNotContainsString('rejectShopOwner', $action, $route->uri());
        }
    }

    public function test_subscription_mutations_are_withdrawn_until_phase_five(): void
    {
        foreach ([
            'admin.subscriptions.cancel',
            'admin.subscriptions.upgrade',
            'admin.subscriptions.downgrade',
        ] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }
    }
}
