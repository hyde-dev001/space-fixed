<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\Adapters\OrderRefundAttentionAdapter;
use App\Services\OwnerActionCenter\Adapters\RepairRefundAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class OwnerActionCenterRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'owner_shell.enabled' => false,
            'owner_shell.allowlisted_shop_ids' => [],
            'owner_action_center.enabled' => false,
            'owner_action_center.allowlisted_shop_ids' => [],
            'owner_action_center.coverage.refunds' => true,
            'owner_action_center.coverage.expenses' => true,
            'owner_action_center.coverage.purchase_requests' => true,
            'owner_action_center.buckets.urgent_exceptions.enabled' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => false,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);
    }

    public function test_action_center_route_is_registered_with_owner_auth_even_when_flags_are_off(): void
    {
        $route = RouteFacade::getRoutes()->getByName('shop-owner.shell.action-center');

        $this->assertNotNull($route);
        $this->assertSame('shop-owner/action-center', $route->uri());
        $this->assertSame('GET', $route->methods()[0]);
        $this->assertContains('auth:shop_owner', $route->middleware());
        $this->assertNotContains('shop.module', $route->gatherMiddleware());
        $this->assertNotContains('erp.audience', $route->gatherMiddleware());
        $this->assertNotContains('erp.actor', $route->gatherMiddleware());
    }

    public function test_owner_outside_phase_three_is_redirected_to_canonical_home_without_a_loop(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        $response = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'));

        $response->assertRedirect(route('shop-owner.shell.home'));
        $this->assertStringNotContainsString('/action-center', (string) $response->headers->get('Location'));
    }

    public function test_selected_owner_receives_full_queue_with_validated_filter_and_pagination_props(): void
    {
        $owner = $this->phaseThreeOwner();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'source' => 'refunds',
                'page' => 2,
                'per_page' => 3,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/ActionCenter', false)
                ->where('source', 'refunds')
                ->where('bucket', 'needs_my_decision')
                ->where('page', 1)
                ->where('per_page', 3)
                ->where('ownerActionCenter.coverage', 'refunds')
                ->where('ownerActionCenter.bucket', 'needs_my_decision')
                ->where('ownerActionCenter.pagination.per_page', 3));
    }

    public function test_exception_bucket_uses_bucket_scoped_source_and_independent_page_state(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'bucket' => 'urgent_exceptions',
                'source' => 'compliance',
                'page' => 2,
                'per_page' => 3,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/ActionCenter', false)
                ->where('bucket', 'urgent_exceptions')
                ->where('source', 'compliance')
                ->where('page', 1)
                ->where('ownerActionCenter.bucket', 'urgent_exceptions')
                ->where('ownerActionCenter.coverage', 'compliance')
                ->has('bucketSummaries.needs_my_decision')
                ->has('bucketSummaries.urgent_exceptions'));
    }

    public function test_waiting_bucket_accepts_its_scoped_source_and_resets_pagination(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.waiting_on_others.enabled' => true,
            'owner_action_center.buckets.waiting_on_others.coverage.compliance' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'bucket' => 'waiting_on_others',
                'source' => 'compliance',
                'page' => 2,
                'per_page' => 3,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/ActionCenter', false)
                ->where('bucket', 'waiting_on_others')
                ->where('source', 'compliance')
                ->where('page', 1)
                ->where('ownerActionCenter.bucket', 'waiting_on_others')
                ->where('ownerActionCenter.coverage', 'compliance')
                ->where('ownerActionCenter.degradation_status', 'none')
                ->has('bucketSummaries.needs_my_decision')
                ->has('bucketSummaries.urgent_exceptions')
                ->has('bucketSummaries.waiting_on_others'));
    }

    public function test_disabled_exception_bucket_normalizes_to_the_default_decision_bucket(): void
    {
        $owner = $this->phaseThreeOwner();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'bucket' => 'urgent_exceptions',
                'source' => 'compliance',
                'page' => 4,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bucket', 'needs_my_decision')
                ->where('source', 'all')
                ->where('page', 1)
                ->where('ownerActionCenter.bucket', 'needs_my_decision'));
    }

    public function test_disabling_waiting_leaves_existing_bucket_summaries_unchanged(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.waiting_on_others.enabled' => false,
            'owner_action_center.buckets.waiting_on_others.coverage.compliance' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'bucket' => 'waiting_on_others',
                'source' => 'compliance',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bucket', 'needs_my_decision')
                ->where('source', 'all')
                ->has('bucketSummaries.needs_my_decision')
                ->has('bucketSummaries.urgent_exceptions')
                ->missing('bucketSummaries.waiting_on_others'));
    }

    public function test_selected_canonical_home_receives_a_bounded_summary(): void
    {
        $owner = $this->phaseThreeOwner();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('showPhaseThreePlaceholders', true)
                ->where('ownerActionCenter.coverage', 'all')
                ->where('ownerActionCenter.pagination.per_page', 3));
    }

    public function test_canonical_home_receives_separate_decision_and_exception_summaries(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ownerActionCenter.bucket', 'needs_my_decision')
                ->where('ownerUrgentExceptions.bucket', 'urgent_exceptions')
                ->where('ownerUrgentExceptions.pagination.per_page', 3));
    }

    public function test_canonical_home_receives_an_independent_waiting_summary(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.waiting_on_others.enabled' => true,
            'owner_action_center.buckets.waiting_on_others.coverage.compliance' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ownerActionCenter.bucket', 'needs_my_decision')
                ->where('ownerUrgentExceptions.bucket', 'urgent_exceptions')
                ->where('ownerWaitingOnOthers.bucket', 'waiting_on_others')
                ->where('ownerWaitingOnOthers.pagination.per_page', 3));
    }

    public function test_existing_dashboard_never_reads_phase_three_attention_sources(): void
    {
        $owner = $this->phaseThreeOwner();
        $forbiddenQueries = [];
        $forbiddenTerms = ['approval', 'exception', 'notification', 'refund', 'repair', 'payroll', 'attention'];

        DB::listen(function (QueryExecuted $query) use (&$forbiddenQueries, $forbiddenTerms): void {
            $sql = strtolower($query->sql);

            foreach ($forbiddenTerms as $term) {
                if (str_contains($sql, $term)) {
                    $forbiddenQueries[] = $query->sql;
                    break;
                }
            }
        });

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->missing('ownerActionCenter'));

        $this->assertSame([], $forbiddenQueries);
    }

    public function test_phase_two_canonical_home_keeps_placeholders_outside_the_phase_three_cohort(): void
    {
        $owner = $this->owner();
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('showPhaseThreePlaceholders', true)
                ->missing('ownerActionCenter'));
    }

    public function test_failed_refund_adapter_returns_partial_queue_data(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
        ]);
        $this->bindAdapter(OrderRefundAttentionAdapter::class, 'order_refunds', 'refunds', new RuntimeException('order source unavailable'));
        $this->bindAdapter(RepairRefundAttentionAdapter::class, 'repair_refunds', 'refunds');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ownerActionCenter.degradation_status', 'partial')
                ->where('ownerActionCenter.health.failed_adapter_keys', ['order_refunds'])
                ->where('ownerActionCenter.health.healthy_adapter_keys', ['repair_refunds']));
    }

    public function test_all_enabled_adapters_failing_returns_unavailable_queue_data(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
        ]);
        $this->bindAdapter(OrderRefundAttentionAdapter::class, 'order_refunds', 'refunds', new RuntimeException('order source unavailable'));
        $this->bindAdapter(RepairRefundAttentionAdapter::class, 'repair_refunds', 'refunds', new RuntimeException('repair source unavailable'));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ownerActionCenter.degradation_status', 'unavailable')
                ->where('ownerActionCenter.pagination.total', 0)
                ->where('ownerActionCenter.health.failed_adapter_keys', ['order_refunds', 'repair_refunds']));
    }

    public function test_common_coordinator_failure_redirects_action_center_and_home_keeps_phase_two_placeholders(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
        ]);
        $this->bindAdapter(OrderRefundAttentionAdapter::class, 'order_refunds', 'refunds', new InvalidArgumentException('invalid shared result'));
        $this->bindAdapter(RepairRefundAttentionAdapter::class, 'repair_refunds', 'refunds');

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertRedirect(route('shop-owner.shell.home'));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('showPhaseThreePlaceholders', true)
                ->missing('ownerActionCenter'));
    }

    private function phaseThreeOwner(): ShopOwner
    {
        $owner = $this->owner();

        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        return $owner;
    }

    private function owner(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
    }

    private function bindAdapter(string $class, string $key, string $coverage, ?Throwable $failure = null): void
    {
        $adapter = new class($key, $coverage, $failure) implements OwnerAttentionAdapter {
            public function __construct(
                private readonly string $key,
                private readonly string $coverage,
                private readonly ?Throwable $failure,
            ) {}

            public function adapterKey(): string
            {
                return $this->key;
            }

            public function coverageSource(): string
            {
                return $this->coverage;
            }

            public function primaryBucket(): string
            {
                return 'needs_my_decision';
            }

            public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new OwnerAttentionAdapterResult([], 0);
            }
        };

        app()->instance($class, $adapter);
    }
}
