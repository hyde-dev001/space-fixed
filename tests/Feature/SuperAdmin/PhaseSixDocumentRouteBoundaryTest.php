<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Http\Middleware\AttachPrivilegedCorrelationId;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PhaseSixDocumentRouteBoundaryTest extends TestCase
{
    public function test_phase_six_registers_only_the_canonical_owner_and_privileged_renewal_routes(): void
    {
        $routes = [
            'shop-owner.compliance-documents.renewals.store' => [
                'POST',
                'shop-owner/compliance-documents/{document}/renewals',
            ],
            'admin.document-renewals.index' => [
                'GET',
                'admin/document-renewals',
            ],
            'admin.document-renewals.approve' => [
                'POST',
                'admin/document-renewals/{document}/approve',
            ],
            'admin.document-renewals.reject' => [
                'POST',
                'admin/document-renewals/{document}/reject',
            ],
        ];

        foreach ($routes as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' must be registered');
            $this->assertSame($uri, $route->uri());
            $this->assertContains($method, $route->methods());
        }
    }

    public function test_privileged_renewal_routes_keep_the_privileged_authentication_stack_and_capability_gate(): void
    {
        foreach ([
            'admin.document-renewals.index',
            'admin.document-renewals.approve',
            'admin.document-renewals.reject',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $middleware = $route?->gatherMiddleware() ?? [];

            $this->assertContains(AttachPrivilegedCorrelationId::class, $middleware, $name);
            $this->assertContains('super_admin.auth', $middleware, $name);
            $this->assertContains('privileged.active', $middleware, $name);
            $this->assertContains('privileged.mfa', $middleware, $name);
            $this->assertContains('privileged.capability:review_registrations', $middleware, $name);
        }
    }

    public function test_owner_renewal_is_authenticated_and_no_generic_document_mutation_route_exists(): void
    {
        $ownerRoute = Route::getRoutes()->getByName('shop-owner.compliance-documents.renewals.store');
        $ownerMiddleware = $ownerRoute?->gatherMiddleware() ?? [];

        $this->assertContains('auth:shop_owner', $ownerMiddleware);

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $isDocumentMutation = str_contains($uri, 'shop-documents')
                || str_contains($uri, 'compliance-documents');

            if ($isDocumentMutation) {
                $this->assertNotContains('PUT', $route->methods(), $uri);
                $this->assertNotContains('PATCH', $route->methods(), $uri);
                $this->assertNotContains('DELETE', $route->methods(), $uri);
            }
        }
    }

    public function test_renewal_endpoints_are_not_public(): void
    {
        $this->get('/admin/document-renewals')->assertRedirect();
        $this->post('/shop-owner/compliance-documents/1/renewals')->assertRedirect();
    }
}
