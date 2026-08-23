<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ErpRouteMatrixCommandTest extends TestCase
{
    public function test_matrix_command_emits_deterministic_review_columns_and_warning(): void
    {
        $exitCode = Artisan::call('erp:route-matrix');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('# Shop Owner ERP Route Matrix', $output);
        $this->assertStringContainsString(
            'Method | Employee route | Owner policy | Owner exposure/route | Component/controller | Supporting APIs | ERP group | Module gate | Business type | Employee rule | Domain rule | Risk | Actor persistence | Self-service',
            $output,
        );
        $this->assertStringContainsString('Generated review artifact; not a policy source.', $output);
        $this->assertStringContainsString('GET | `erp.hr`', $output);
        $this->assertStringContainsString('GET | `shop-owner.erp.finance.invoices` | allowed', $output);
    }

    public function test_matrix_command_fails_when_a_configured_route_is_not_loaded(): void
    {
        $routes = config('shop_modules.routes', []);
        $routes['testing.unloaded-route'] = [
            'methods' => ['GET'],
            'classification' => 'excluded',
            'audience' => 'user',
            'actor_guard' => 'user',
            'module_keys' => [],
            'mode' => null,
            'registration_types' => [],
            'business_types' => [],
            'action' => 'view',
            'owner_access' => 'denied',
            'owner_denial_reason' => 'not_an_erp_route',
            'domain_rule' => null,
            'risk_tier' => 'normal',
            'paired_route' => null,
            'navigation_group' => null,
            'self_service' => false,
            'supporting_routes' => [],
            'actor_persistence' => 'not_applicable',
        ];
        config(['shop_modules.routes' => $routes]);

        $exitCode = Artisan::call('erp:route-matrix');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('configured route is not loaded', Artisan::output());
    }
}
