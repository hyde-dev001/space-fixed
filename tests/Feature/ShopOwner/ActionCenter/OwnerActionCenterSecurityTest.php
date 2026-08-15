<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PurchaseRequest;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
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
use RuntimeException;
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
            'owner_action_center.buckets.urgent_exceptions.enabled' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.refunds' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.logistics' => false,
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
                        'bucket',
                        'duration_ms',
                        'result_count',
                        'source',
                        'page',
                        'per_page',
                        'correlation_id',
                    ]
                    && $context['bucket'] === 'needs_my_decision'
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

    public function test_compliance_projection_is_tenant_scoped_and_excludes_sensitive_document_fields(): void
    {
        $owner = $this->phaseThreeOwner();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
        ]);
        $ownerDocument = $this->expiringDocument($owner, $reviewer, 'private/owner-secret.pdf');
        $otherDocument = $this->expiringDocument($otherOwner, $reviewer, 'private/other-secret.pdf');

        $response = $this->actingAs($owner, 'shop_owner')->get(route('shop-owner.shell.action-center', [
            'bucket' => 'urgent_exceptions',
            'source' => 'compliance',
        ]))->assertOk();

        $items = $response->inertiaProps('ownerActionCenter.items');
        $this->assertSame([$ownerDocument->id], array_column($items, 'source_id'));
        $this->assertNotContains($otherDocument->id, array_column($items, 'source_id'));
        $serialized = json_encode($items, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('owner-secret', $serialized);
        $this->assertStringNotContainsString('other-secret', $serialized);
        $this->assertStringNotContainsString('checksum_sha256', $serialized);
    }

    public function test_compliance_failure_keeps_decisions_healthy_and_marks_exceptions_unavailable(): void
    {
        $owner = $this->phaseThreeOwner();
        $request = $this->createPurchaseRequest($owner);
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
        ]);
        $this->bindAdapter(
            'App\\Services\\OwnerActionCenter\\Adapters\\ComplianceDocumentAttentionAdapter',
            'compliance_documents',
            'compliance',
            failure: new RuntimeException('compliance read failed'),
            bucket: 'urgent_exceptions',
        );

        $response = $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk();

        $this->assertSame([$request->id], array_column(
            $response->inertiaProps('ownerActionCenter.items'),
            'source_id',
        ));
        $this->assertSame('none', $response->inertiaProps('ownerActionCenter.degradation_status'));
        $this->assertSame('unavailable', $response->inertiaProps('ownerUrgentExceptions.degradation_status'));
        $this->assertSame(['compliance_documents'], $response->inertiaProps('ownerUrgentExceptions.health.failed_adapter_keys'));
    }

    public function test_blocked_exception_sources_are_not_resolved_or_reported_as_failures(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.buckets.urgent_exceptions.enabled' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => true,
            'owner_action_center.buckets.urgent_exceptions.coverage.refunds' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.logistics' => false,
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->get(route('shop-owner.shell.action-center', [
            'bucket' => 'urgent_exceptions',
        ]))->assertOk();

        $this->assertSame(['compliance_documents'], $response->inertiaProps('ownerActionCenter.health.enabled_adapter_keys'));
        $this->assertSame([], $response->inertiaProps('ownerActionCenter.health.failed_adapter_keys'));
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
        string $bucket = 'needs_my_decision',
    ): void {
        $adapter = new class($key, $coverage, $items, $failure, $bucket) implements OwnerAttentionAdapter {
            /** @param array<int, OwnerAttentionItem> $items */
            public function __construct(
                private readonly string $key,
                private readonly string $coverage,
                private readonly array $items,
                private readonly ?Throwable $failure,
                private readonly string $bucket,
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
                return $this->bucket;
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
            primaryBucket: 'needs_my_decision',
            module: 'finance',
            title: $title,
            conciseSummary: $summary,
            priorityTier: 'high',
            materialityTier: 'medium',
            comparableMonetaryExposure: 100.0,
            urgencyAt: null,
            actionableSince: '2026-08-15T09:00:00+08:00',
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: 'expenses',
            destinationUrl: '/shop-owner/expense-approvals?expense=1',
        );
    }

    private function expiringDocument(ShopOwner $owner, SuperAdmin $reviewer, string $filePath): ShopDocument
    {
        return ShopDocument::query()->create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 1,
            'file_path' => $filePath,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'dated',
            'expires_on' => now()->addDays(5)->toDateString(),
            'reviewed_by_super_admin_id' => $reviewer->id,
            'reviewed_at' => now()->subMonth(),
            'checksum_sha256' => hash('sha256', $filePath),
        ]);
    }
}
