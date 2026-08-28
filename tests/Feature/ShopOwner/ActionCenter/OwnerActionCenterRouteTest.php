<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PriceChangeRequest;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
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
            'owner_action_center.coverage.prices' => false,
            'owner_action_center.coverage.payslips' => false,
            'owner_action_center.coverage.salary_changes' => false,
            'owner_action_center.coverage.expenses' => true,
            'owner_action_center.coverage.purchase_requests' => true,
            'owner_action_center.coverage.suspensions' => false,
            'owner_action_center.coverage.repair_rejections' => false,
            'owner_action_center.buckets.urgent_exceptions.enabled' => false,
            'owner_action_center.buckets.urgent_exceptions.coverage.compliance' => false,
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

    public function test_action_center_summary_returns_only_the_bounded_decision_total_for_selected_owner(): void
    {
        $owner = $this->phaseThreeOwner();
        config([
            'owner_action_center.coverage.prices' => false,
            'owner_action_center.coverage.payslips' => false,
            'owner_action_center.coverage.salary_changes' => false,
            'owner_action_center.coverage.expenses' => false,
            'owner_action_center.coverage.purchase_requests' => false,
            'owner_action_center.coverage.repair_rejections' => false,
        ]);
        $this->bindAdapter(OrderRefundAttentionAdapter::class, 'order_refunds', 'refunds', qualifyingCount: 2);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/action-center/summary')
            ->assertOk()
            ->assertExactJson(['pending_count' => 2]);
    }

    public function test_action_center_summary_is_not_available_when_rollout_or_canonical_guards_fail(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/action-center/summary')
            ->assertNotFound();
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

    public function test_action_center_exposes_only_the_owner_approval_queue(): void
    {
        $owner = $this->phaseThreeOwner();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bucket', 'needs_my_decision')
                ->where('ownerActionCenter.bucket', 'needs_my_decision')
                ->missing('bucketSummaries'));
    }

    public function test_individual_owner_approval_center_exposes_refunds_only(): void
    {
        $owner = $this->phaseThreeOwner();
        $owner->forceFill([
            'registration_type' => 'individual',
            'business_type' => 'both',
        ])->save();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalCoverageSources', ['refunds'])
                ->where('ownerActionCenter.health.enabled_adapter_keys', ['order_refunds', 'repair_refunds'])
                ->where('ownerActionCenter.coverage', 'all'));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'approval' => 'salary_change:14',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalSelection', null)
                ->where('approvalSelectionError', 'invalid'));
    }

    public function test_owner_approval_history_exposes_completed_purchase_request_decisions(): void
    {
        $owner = $this->phaseThreeOwner();
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $decisionAt = now()->subHour();

        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_finance_final',
            'requires_owner_approval' => true,
            'approved_by_shop_owner_id' => $owner->id,
            'shop_owner_approved_at' => $decisionAt,
            'total_cost' => 1250,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'view' => 'history',
                'source' => 'purchase_requests',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'history')
                ->where('source', 'purchase_requests')
                ->where('ownerActionCenter.bucket', 'needs_my_decision')
                ->where('approvalHistory.pagination.total', 1)
                ->where('approvalHistory.items.0.source_type', 'purchase_request')
                ->where('approvalHistory.items.0.source_id', $purchaseRequest->id)
                ->where('approvalHistory.items.0.status', 'approved'));
    }

    public function test_owner_approval_history_reads_generic_decisions_recorded_for_the_owner_user(): void
    {
        $owner = $this->phaseThreeOwner();
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $ownerUser = User::factory()->create(['shop_owner_id' => $owner->id]);
        $decisionAt = now()->subHours(2);
        $expense = Expense::create([
            'reference' => 'EXP-HISTORY-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'description' => 'Generic approval history expense',
            'amount' => 500,
            'tax_amount' => 0,
            'status' => 'approved',
            'shop_id' => $owner->id,
        ]);

        Approval::create([
            'shop_owner_id' => $ownerUser->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => 'Generic approval history expense',
            'amount' => 500,
            'requested_by' => $requester->id,
            'current_level' => 2,
            'total_levels' => 2,
            'status' => 'approved',
            'approval_roles' => ['1' => 'finance', '2' => 'shop_owner'],
            'current_approver_role' => 'shop_owner',
            'level_reviewers' => [
                '2' => [
                    'user_id' => $ownerUser->id,
                    'action' => 'approved',
                    'reviewed_at' => $decisionAt->toIso8601String(),
                ],
            ],
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'view' => 'history',
                'source' => 'expenses',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalHistory.pagination.total', 1)
                ->where('approvalHistory.items.0.source_type', 'expense')
                ->where('approvalHistory.items.0.source_id', $expense->id)
                ->where('approvalHistory.items.0.status', 'approved'));
    }

    public function test_owner_approval_history_exposes_final_decisions_when_owner_stage_was_skipped(): void
    {
        $owner = $this->phaseThreeOwner();
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $financeReviewer = User::factory()->create([
            'name' => 'Finance Reviewer',
            'shop_owner_id' => $owner->id,
        ]);
        $decisionAt = now()->subHour();
        $expense = Expense::create([
            'reference' => 'EXP-SKIPPED-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'description' => 'Finance-only approval history expense',
            'amount' => 750,
            'tax_amount' => 0,
            'status' => 'approved',
            'shop_id' => $owner->id,
        ]);

        Approval::create([
            'shop_owner_id' => $financeReviewer->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => 'Finance-only approval history expense',
            'amount' => 750,
            'requested_by' => $requester->id,
            'reviewed_by' => $financeReviewer->id,
            'reviewed_at' => $decisionAt,
            'current_level' => 1,
            'total_levels' => 1,
            'status' => 'approved',
            'approval_roles' => ['1' => 'finance'],
            'current_approver_role' => 'finance',
            'level_reviewers' => [
                '1' => [
                    'role' => 'finance',
                    'user_id' => $financeReviewer->id,
                    'action' => 'approved',
                    'reviewed_at' => $decisionAt->toIso8601String(),
                ],
            ],
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'view' => 'history',
                'source' => 'expenses',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalHistory.pagination.total', 1)
                ->where('approvalHistory.items.0.source_type', 'expense')
                ->where('approvalHistory.items.0.source_id', $expense->id)
                ->where('approvalHistory.items.0.status', 'approved')
                ->where('approvalHistory.items.0.reviewed_by', 'Finance Reviewer')
                ->where('approvalHistory.items.0.concise_summary', 'Approved by Finance; owner approval was not required.'));
    }

    public function test_owner_approval_history_exposes_finance_final_direct_decisions_when_owner_stage_was_skipped(): void
    {
        $owner = $this->phaseThreeOwner();
        $financeReviewer = User::factory()->create([
            'name' => 'Finance Reviewer',
            'shop_owner_id' => $owner->id,
        ]);
        $refundDecisionAt = now()->subHour();
        $purchaseDecisionAt = now()->subHours(2);

        $refund = OrderRefund::factory()->create([
            'shop_owner_id' => $owner->id,
            'requires_owner_approval' => false,
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'shop_owner_approved_at' => $refundDecisionAt,
            'shop_owner_approved_by' => null,
            'finance_status' => 'approved',
            'finance_approved_at' => $refundDecisionAt,
            'finance_approved_by' => $financeReviewer->id,
        ]);

        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requires_owner_approval' => false,
            'status' => 'approved',
            'approved_by' => $financeReviewer->id,
            'approved_date' => $purchaseDecisionAt,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'view' => 'history',
                'source' => 'all',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalHistory.pagination.total', 2)
                ->where('approvalHistory.items.0.source_type', 'order_refund')
                ->where('approvalHistory.items.0.source_id', $refund->id)
                ->where('approvalHistory.items.0.status', 'approved')
                ->where('approvalHistory.items.0.reviewed_by', 'Finance Reviewer')
                ->where('approvalHistory.items.0.concise_summary', 'Approved by Finance; owner approval was not required.')
                ->where('approvalHistory.items.1.source_type', 'purchase_request')
                ->where('approvalHistory.items.1.source_id', $purchaseRequest->id)
                ->where('approvalHistory.items.1.status', 'approved')
                ->where('approvalHistory.items.1.reviewed_by', 'Finance Reviewer')
                ->where('approvalHistory.items.1.concise_summary', 'Approved by Finance; owner approval was not required.'));
    }

    public function test_owner_approval_history_exposes_manager_final_repair_decision_when_owner_stage_was_skipped(): void
    {
        $owner = $this->phaseThreeOwner();
        $manager = User::factory()->create([
            'name' => 'Repair Manager',
            'shop_owner_id' => $owner->id,
        ]);
        $decisionAt = now()->subHour();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requires_owner_approval' => false,
            'status' => 'rejected',
            'manager_decision' => 'approve_rejection',
            'manager_reviewed_at' => $decisionAt,
            'manager_reviewed_by' => $manager->id,
            'repairer_rejection_reason' => 'Repair cannot be completed safely.',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'view' => 'history',
                'source' => 'repair_rejections',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalHistory.pagination.total', 1)
                ->where('approvalHistory.items.0.source_type', 'repair_rejection')
                ->where('approvalHistory.items.0.source_id', $repair->id)
                ->where('approvalHistory.items.0.status', 'rejected')
                ->where('approvalHistory.items.0.reviewed_by', 'Repair Manager')
                ->where('approvalHistory.items.0.concise_summary', 'Rejected by Manager; owner approval was not required.'));
    }

    public function test_legacy_exception_and_waiting_bucket_urls_redirect_to_approval_center(): void
    {
        $owner = $this->phaseThreeOwner();

        foreach (['urgent_exceptions', 'waiting_on_others'] as $bucket) {
            $this->actingAs($owner, 'shop_owner')
                ->get(route('shop-owner.shell.action-center', [
                    'bucket' => $bucket,
                    'source' => 'all',
                ]))
                ->assertRedirect(route('shop-owner.shell.action-center', [
                    'source' => 'all',
                ]));
        }
    }

    public function test_invalid_approval_selection_keeps_the_queue_available_and_marks_the_link_invalid(): void
    {
        $owner = $this->phaseThreeOwner();

        foreach (['1 OR 1=1', 'order_refund:9007199254740992'] as $approval) {
            $this->actingAs($owner, 'shop_owner')
                ->get(route('shop-owner.shell.action-center', [
                    'approval' => $approval,
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('approvalSelection', null)
                    ->where('approvalSelectionError', 'invalid')
                    ->where('ownerActionCenter.bucket', 'needs_my_decision'));
        }
    }

    public function test_valid_approval_selection_is_whitelisted_and_positive(): void
    {
        $owner = $this->phaseThreeOwner();

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'approval' => 'repair_rejection:123',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalSelection.sourceType', 'repair_rejection')
                ->where('approvalSelection.sourceId', 123)
                ->where('approvalSelectionError', null));
    }

    public function test_legacy_owner_approval_routes_redirect_to_typed_action_center_selections(): void
    {
        $owner = $this->phaseThreeOwner();

        $cases = [
            ['shop-owner.refund-approvals', ['refund_type' => 'order', 'refund' => '11'], 'order_refund:11'],
            ['shop-owner.price-approvals', ['id' => '12'], 'product_price_change:12'],
            ['shop-owner.payslip-approvals', ['payroll_id' => '13'], 'payslip:13'],
            ['shop-owner.salary-adjustment-approvals', ['salary_change_id' => '14'], 'salary_change:14'],
            ['shop-owner.purchase-request-approval', ['purchase_request' => '15'], 'purchase_request:15'],
            ['shop-owner.expense-approvals', ['expense' => '16'], 'expense:16'],
            ['shop-owner.repair-reject-approval', ['repair_id' => '17'], 'repair_rejection:17'],
        ];

        foreach ($cases as [$routeName, $query, $approval]) {
            $this->actingAs($owner, 'shop_owner')
                ->get(route($routeName, $query))
                ->assertRedirect(route('shop-owner.shell.action-center', [
                    'bucket' => 'needs_my_decision',
                    'approval' => $approval,
                ]));
        }
    }

    public function test_legacy_owner_approval_routes_discard_malformed_selection_without_disclosure(): void
    {
        $owner = $this->phaseThreeOwner();

        foreach ([
            ['shop-owner.refund-approvals', ['refund_type' => 'order', 'refund' => '0']],
            ['shop-owner.price-approvals', ['id' => '1 OR 1=1']],
            ['shop-owner.payslip-approvals', ['payroll_id' => '9007199254740992']],
            ['shop-owner.salary-adjustment-approvals', ['salary_change_id' => '']],
            ['shop-owner.purchase-request-approval', ['purchase_request' => 'abc']],
            ['shop-owner.expense-approvals', ['expense' => '-4']],
            ['shop-owner.repair-reject-approval', ['repair_id' => '0']],
        ] as [$routeName, $query]) {
            $response = $this->actingAs($owner, 'shop_owner')
                ->get(route($routeName, $query));

            $response->assertRedirect(route('shop-owner.shell.action-center'));
            $this->assertStringNotContainsString('approval=', (string) $response->headers->get('Location'));
        }
    }

    public function test_new_approval_detail_reads_are_tenant_scoped_and_keep_completed_context(): void
    {
        $owner = $this->phaseThreeOwner();
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'approved',
        ]);
        $otherPurchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $otherOwner->id,
        ]);
        $expense = Expense::create([
            'reference' => 'EXP-DETAIL-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'description' => 'Action Center detail expense',
            'amount' => 1000,
            'tax_amount' => 0,
            'status' => 'approved',
            'shop_id' => $owner->id,
        ]);
        $otherExpense = Expense::create([
            'reference' => 'EXP-DETAIL-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'description' => 'Other shop expense',
            'amount' => 1000,
            'tax_amount' => 0,
            'status' => 'approved',
            'shop_id' => $otherOwner->id,
        ]);
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'payment_status' => 'paid',
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'status' => 'succeeded',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
        ]);
        $otherCustomer = User::factory()->create();
        $otherOrder = Order::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'customer_id' => $otherCustomer->id,
            'payment_status' => 'paid',
        ]);
        $otherRefund = OrderRefund::factory()->create([
            'order_id' => $otherOrder->id,
            'customer_id' => $otherCustomer->id,
            'shop_owner_id' => $otherOwner->id,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/purchase-requests/{$purchaseRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $purchaseRequest->id);
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('id', $expense->id);
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/refunds/{$refund->id}")
            ->assertOk()
            ->assertJsonPath('id', $refund->id)
            ->assertJsonPath('status', 'Approved');

        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/purchase-requests/{$otherPurchaseRequest->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/expenses/{$otherExpense->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/refunds/{$otherRefund->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/purchase-requests/999999')
            ->assertNotFound();
    }

    public function test_price_and_repair_rejection_details_are_tenant_scoped(): void
    {
        $owner = $this->phaseThreeOwner();
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);

        $product = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Action Center Detail Product',
            'slug' => 'action-center-detail-product-'.uniqid(),
            'description' => 'Product detail',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $priceChange = PriceChangeRequest::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 140,
            'reason' => 'Detail test',
            'requested_by' => $requester->id,
            'status' => 'owner_approved',
            'shop_owner_id' => $owner->id,
        ]);
        $otherProduct = Product::create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Other Detail Product',
            'slug' => 'other-detail-product-'.uniqid(),
            'description' => 'Other product detail',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $otherPriceChange = PriceChangeRequest::create([
            'product_id' => $otherProduct->id,
            'product_name' => $otherProduct->name,
            'current_price' => 100,
            'proposed_price' => 140,
            'reason' => 'Other detail test',
            'requested_by' => User::factory()->create(['shop_owner_id' => $otherOwner->id])->id,
            'status' => 'owner_approved',
            'shop_owner_id' => $otherOwner->id,
        ]);
        $repairService = RepairService::create([
            'name' => 'Action Center Detail Repair',
            'category' => 'Restoration',
            'price' => 1400,
            'old_price' => 1200,
            'duration' => '2 days',
            'description' => 'Repair detail',
            'change_reason' => 'Detail test',
            'status' => 'Active',
            'shop_owner_id' => $owner->id,
            'created_by' => $requester->id,
            'updated_by' => $requester->id,
        ]);
        $otherRepairService = RepairService::create([
            'name' => 'Other Detail Repair',
            'category' => 'Restoration',
            'price' => 1400,
            'old_price' => 1200,
            'duration' => '2 days',
            'description' => 'Other repair detail',
            'change_reason' => 'Other detail test',
            'status' => 'Active',
            'shop_owner_id' => $otherOwner->id,
        ]);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'rejected',
            'requires_owner_approval' => true,
            'repairer_rejected_at' => now(),
        ]);
        $otherRepair = RepairRequest::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'status' => 'rejected',
            'requires_owner_approval' => true,
            'repairer_rejected_at' => now(),
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/price-changes/{$priceChange->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $priceChange->id);
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/repair-price-changes/{$repairService->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $repairService->id);
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/repairs/rejection-pending/{$repair->id}")
            ->assertOk()
            ->assertJsonPath('repair.id', $repair->id);

        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/price-changes/{$otherPriceChange->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/repair-price-changes/{$otherRepairService->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'shop_owner')
            ->getJson("/api/shop-owner/repairs/rejection-pending/{$otherRepair->id}")
            ->assertNotFound();
    }

    public function test_disabled_coverage_keeps_a_valid_selection_non_mutating(): void
    {
        $owner = $this->phaseThreeOwner();
        config(['owner_action_center.coverage.purchase_requests' => false]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.action-center', [
                'approval' => 'purchase_request:123',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalSelection.sourceType', 'purchase_request')
                ->where('approvalSelection.sourceId', 123)
                ->where('approvalSelectionError', null)
                ->where('ownerActionCenter.coverage', 'all'));
    }

    public function test_exception_bucket_is_no_longer_served_by_the_approval_center(): void
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
            ->assertRedirect(route('shop-owner.shell.action-center', [
                'source' => 'all',
            ]));
    }

    public function test_waiting_bucket_is_no_longer_served_by_the_approval_center(): void
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
            ->assertRedirect(route('shop-owner.shell.action-center', [
                'source' => 'all',
            ]));
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
            ->assertRedirect(route('shop-owner.shell.action-center', [
                'source' => 'all',
            ]));
    }

    public function test_waiting_bucket_redirect_does_not_depend_on_bucket_configuration(): void
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
            ->assertRedirect(route('shop-owner.shell.action-center', [
                'source' => 'all',
            ]));
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

    public function test_canonical_home_receives_only_the_decision_summary(): void
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
                ->missing('ownerUrgentExceptions')
                ->missing('ownerWaitingOnOthers'));
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

    private function bindAdapter(
        string $class,
        string $key,
        string $coverage,
        ?Throwable $failure = null,
        int $qualifyingCount = 0,
    ): void
    {
        $adapter = new class($key, $coverage, $failure, $qualifyingCount) implements OwnerAttentionAdapter {
            public function __construct(
                private readonly string $key,
                private readonly string $coverage,
                private readonly ?Throwable $failure,
                private readonly int $qualifyingCount,
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

                return new OwnerAttentionAdapterResult([], $this->qualifyingCount);
            }
        };

        app()->instance($class, $adapter);
    }
}
