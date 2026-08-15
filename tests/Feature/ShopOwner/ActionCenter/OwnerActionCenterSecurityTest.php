<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\ExpenseAttentionAdapter;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Throwable;

final class OwnerActionCenterSecurityTest extends TestCase
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
            'owner_action_center.coverage.refunds' => false,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => true,
        ]);
    }

    public function test_cross_shop_records_are_excluded_from_full_and_home_contracts(): void
    {
        $owner = $this->phaseThreeOwner();
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $ownerRequest = $this->createPurchaseRequest($owner);
        $otherRequest = $this->createPurchaseRequest($otherOwner);

        $fullResponse = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk();
        $this->assertSame(1, $fullResponse->inertiaProps('ownerActionCenter.coverage_counts.purchase_requests'));
        $this->assertSame(1, $fullResponse->inertiaProps('ownerActionCenter.pagination.total'));
        $fullSourceIds = array_column($fullResponse->inertiaProps('ownerActionCenter.items'), 'source_id');
        $this->assertSame([$ownerRequest->id], $fullSourceIds);
        $this->assertNotContains($otherRequest->id, $fullSourceIds);

        $homeResponse = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk();
        $this->assertSame(1, $homeResponse->inertiaProps('ownerActionCenter.coverage_counts.purchase_requests'));
        $this->assertSame(1, $homeResponse->inertiaProps('ownerActionCenter.pagination.total'));
        $homeSourceIds = array_column($homeResponse->inertiaProps('ownerActionCenter.items'), 'source_id');
        $this->assertSame([$ownerRequest->id], $homeSourceIds);
        $this->assertNotContains($otherRequest->id, $homeSourceIds);
    }

    public function test_action_center_rejects_untrusted_source_and_pagination_values(): void
    {
        $owner = $this->phaseThreeOwner();

        foreach ([
            ['source' => 'other'],
            ['page' => '0'],
            ['page' => '101'],
            ['page' => '1.5'],
            ['per_page' => '0'],
            ['per_page' => '51'],
            ['per_page' => '999999999999999999999'],
        ] as $query) {
            $status = $this->actingAs($owner, 'shop_owner')
                ->get(route('shop-owner.shell.action-center', $query))
                ->getStatusCode();

            $this->assertContains($status, [302, 422]);
        }
    }

    public function test_operational_logs_are_bounded_and_exclude_business_record_content(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.expenses' => true,
        ]);
        $this->bindAdapter(
            ExpenseAttentionAdapter::class,
            'expenses',
            'expenses',
            items: [$this->attentionItem('Customer Ana Rivera secret reason', 'Sensitive payment description')],
        );
        Log::spy();

        app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(coverage: 'expenses'),
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                $encodedContext = json_encode($context);

                return $message === 'owner_action_center.adapter_read'
                    && array_keys($context) === [
                        'shop_id',
                        'adapter_key',
                        'coverage_source',
                        'duration_ms',
                        'result_count',
                        'correlation_id',
                    ]
                    && ! str_contains((string) $encodedContext, 'Ana Rivera')
                    && ! str_contains((string) $encodedContext, 'Sensitive payment description')
                    && ! array_key_exists('amount', $context)
                    && ! array_key_exists('title', $context)
                    && ! array_key_exists('description', $context);
            })
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'owner_action_center.read'
                    && array_keys($context) === [
                        'shop_id',
                        'enabled_adapter_keys',
                        'healthy_adapter_keys',
                        'failed_adapter_keys',
                        'degradation_status',
                        'duration_ms',
                        'result_count',
                        'source',
                        'page',
                        'per_page',
                        'correlation_id',
                    ]
                    && $context['source'] === 'expenses'
                    && $context['page'] === 1
                    && $context['per_page'] === 20
                    && ! array_key_exists('title', $context)
                    && ! array_key_exists('amount', $context);
            })
            ->once();
    }

    public function test_authorization_failures_are_not_converted_to_successful_empty_results(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.expenses' => true,
        ]);
        $this->bindAdapter(
            ExpenseAttentionAdapter::class,
            'expenses',
            'expenses',
            failure: new AuthorizationException('owner authorization failed'),
        );

        $this->expectException(AuthorizationException::class);

        app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(coverage: 'expenses'),
        );
    }

    public function test_tenant_lookup_failures_are_not_converted_to_successful_empty_results(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.expenses' => true,
        ]);
        $failure = (new ModelNotFoundException())->setModel(PurchaseRequest::class, [999]);
        $this->bindAdapter(
            ExpenseAttentionAdapter::class,
            'expenses',
            'expenses',
            failure: $failure,
        );

        $this->expectException(ModelNotFoundException::class);

        app(OwnerActionCenterService::class)->queueForActionCenter(
            $owner,
            new OwnerAttentionQuery(coverage: 'expenses'),
        );
    }

    public function test_route_failure_redirects_once_to_home_without_an_action_center_loop(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.expenses' => true,
        ]);
        $this->bindAdapter(
            ExpenseAttentionAdapter::class,
            'expenses',
            'expenses',
            failure: new AuthorizationException('owner authorization failed'),
        );

        $response = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'));

        $response->assertRedirect(route('shop-owner.shell.home'));
        $this->assertStringNotContainsString('/action-center', (string) $response->headers->get('Location'));
    }

    private function phaseThreeOwner(): ShopOwner
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'owner_action_center.enabled' => true,
            'owner_action_center.allowlisted_shop_ids' => [$owner->getKey()],
        ]);

        return $owner;
    }

    private function createPurchaseRequest(ShopOwner $owner): PurchaseRequest
    {
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);

        return PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_shop_owner',
            'requested_date' => now()->subDay(),
        ]);
    }

    /**
     * @param array<int, OwnerAttentionItem> $items
     */
    private function bindAdapter(
        string $class,
        string $key,
        string $coverage,
        array $items = [],
        ?Throwable $failure = null,
    ): void {
        $adapter = new class($key, $coverage, $items, $failure) implements OwnerAttentionAdapter {
            /** @param array<int, OwnerAttentionItem> $items */
            public function __construct(
                private readonly string $key,
                private readonly string $coverage,
                private readonly array $items,
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

            public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new OwnerAttentionAdapterResult($this->items, count($this->items));
            }
        };

        app()->instance($class, $adapter);
    }

    private function attentionItem(string $title, string $summary): OwnerAttentionItem
    {
        return new OwnerAttentionItem(
            sourceType: 'expense',
            sourceId: 1,
            category: 'expense_approval',
            module: 'finance',
            title: $title,
            conciseSummary: $summary,
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 100.0,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            destinationUrl: '/shop-owner/expense-approvals?expense=1',
        );
    }
}
