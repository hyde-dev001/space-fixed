<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class OwnerErpRolloutConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_compatibility_topology_keeps_only_the_get_redirect(): void
    {
        $disabledTopology = $this->ownerErpTopology();

        $enabledTopology = $this->ownerErpTopology();

        $this->assertNotEmpty($disabledTopology);
        $this->assertSame($disabledTopology, $enabledTopology);
        $this->assertArrayHasKey('shop-owner.erp.workspace', $disabledTopology);
        $this->assertArrayNotHasKey('shop-owner.erp.api.workspace', $disabledTopology);
    }

    public function test_workspace_get_redirects_after_authentication_without_a_picker_flag(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->getJson('/shop-owner/erp/workspace?shop_owner_id=999999')
            ->assertUnauthorized();

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace?shop_owner_id=999999')
            ->assertRedirect(route('shop-owner.shell.home'));
    }

    public function test_workspace_compatibility_redirect_uses_owner_session(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.erp.workspace'))
            ->assertRedirect(route('shop-owner.shell.home'));
    }

    public function test_workspace_api_route_is_retired_with_the_picker_payload(): void
    {
        $this->assertFalse(RouteFacade::has('shop-owner.erp.api.workspace'));
    }

    /**
     * @return array<string, array{methods: array<int, string>, uri: string, middleware: array<int, string>}>
     */
    private function ownerErpTopology(): array
    {
        $topology = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'shop-owner.erp.')) {
                continue;
            }

            $topology[$name] = [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri' => $route->uri(),
                'middleware' => $route->middleware(),
            ];
        }

        ksort($topology);

        return $topology;
    }
}
