<?php

namespace Tests\Feature\Procurement;

use App\Jobs\AutoApproveLowValuePRsJob;
use App\Models\PurchaseRequest;
use App\Models\InventoryItem;
use App\Models\InventoryColorVariant;
use App\Models\InventorySize;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Models\User;
use App\Models\ProcurementSettings;
use App\Services\PurchaseRequestService;
use App\Services\StockRequestApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwner;
    private Supplier $supplier;
    private User $requester;
    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        $this->shopOwner = ShopOwner::factory()->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->requester = User::factory()->for($this->shopOwner)->create();
        $this->finance = User::factory()->for($this->shopOwner)->create();
        $this->give($this->requester, 'procurement.create_purchase_requests');
        $this->give($this->requester, 'procurement.submit_purchase_requests');
        $this->give($this->finance, 'procurement.review_purchase_requests');
        $this->finance->assignRole(Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'user']));
    }

    public function test_pr_follows_finance_then_shop_owner_approval(): void
    {
        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'requested_by' => $this->requester->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->requester)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/submit-to-finance")
            ->assertOk();

        $this->assertTrue($purchaseRequest->fresh()->requires_owner_approval);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->finance->id,
            'action_url' => "/finance?section=purchase-request-approval&purchase_request={$purchaseRequest->id}",
        ]);

        $this->actingAs($this->finance)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Budget checked.',
            ])
            ->assertOk();

        $this->assertSame('pending_shop_owner', $purchaseRequest->fresh()->status);
        $this->assertSame($this->finance->id, $purchaseRequest->fresh()->reviewed_by);
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $this->shopOwner->id,
            'action_url' => "/shop-owner/purchase-request-approval?purchase_request={$purchaseRequest->id}",
        ]);

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Final approval.',
            ])
            ->assertOk();

        $ownerApproved = $purchaseRequest->fresh();
        $this->assertSame('pending_finance_final', $ownerApproved->status);
        $this->assertSame($this->shopOwner->id, $ownerApproved->approved_by_shop_owner_id);
        $this->assertNull($ownerApproved->approved_by);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->finance->id,
            'title' => 'Purchase Request Returned To Finance',
            'action_url' => "/finance?section=purchase-request-approval&purchase_request={$purchaseRequest->id}",
        ]);

        $this->actingAs($this->finance, 'user')
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Funds released.',
            ])
            ->assertOk();

        $final = $purchaseRequest->fresh();
        $this->assertSame('approved', $final->status);
        $this->assertSame($this->finance->id, $final->approved_by);
        $this->assertSame($this->finance->id, $final->reviewed_by);
        $this->assertSame($this->shopOwner->id, $final->approved_by_shop_owner_id);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'action_url' => "/erp/procurement/purchase-request?purchase_request={$purchaseRequest->id}",
        ]);
    }

    public function test_disabled_owner_policy_routes_finance_directly_to_final_release_using_submission_snapshot(): void
    {
        $this->setPurchaseRequestApproval(false);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'requested_by' => $this->requester->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->requester)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/submit-to-finance")
            ->assertOk();

        $this->assertFalse($purchaseRequest->fresh()->requires_owner_approval);
        $this->setPurchaseRequestApproval(true);

        $this->actingAs($this->finance)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Budget checked.',
            ])
            ->assertOk();

        $afterInitialFinance = $purchaseRequest->fresh();
        $this->assertSame('pending_finance_final', $afterInitialFinance->status);
        $this->assertSame($this->finance->id, $afterInitialFinance->reviewed_by);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $this->shopOwner->id,
            'action_url' => "/shop-owner/purchase-request-approval?purchase_request={$purchaseRequest->id}",
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->finance->id,
            'title' => 'Purchase Request Ready For Final Release',
            'action_url' => "/finance?section=purchase-request-approval&purchase_request={$purchaseRequest->id}",
        ]);

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/purchase-requests/{$purchaseRequest->id}/approve")
            ->assertForbidden();

        $this->actingAs($this->finance)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve", [
                'approval_notes' => 'Funds released.',
            ])
            ->assertOk();

        $approved = $purchaseRequest->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertSame($this->finance->id, $approved->reviewed_by);
        $this->assertSame($this->finance->id, $approved->approved_by);
        $this->assertNull($approved->approved_by_shop_owner_id);

        $this->actingAs($this->finance)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve")
            ->assertForbidden();
    }

    public function test_requester_cannot_review_own_purchase_request_even_with_review_permission(): void
    {
        $this->give($this->requester, 'procurement.review_purchase_requests');
        $purchaseRequest = $this->pendingRequest('pending_finance');

        $this->actingAs($this->requester)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending_finance', $purchaseRequest->fresh()->status);
    }

    public function test_finance_cannot_review_a_purchase_request_from_another_shop(): void
    {
        $foreignShopOwner = ShopOwner::factory()->create();
        $foreignFinance = User::factory()->for($foreignShopOwner)->create();
        $this->give($foreignFinance, 'procurement.review_purchase_requests');
        $purchaseRequest = $this->pendingRequest('pending_finance');

        $this->actingAs($foreignFinance)
            ->postJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}/approve")
            ->assertNotFound();

        $this->assertSame('pending_finance', $purchaseRequest->fresh()->status);
    }

    public function test_stock_request_result_notification_returns_inventory_requester_to_inventory_page(): void
    {
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'requested_by' => $this->requester->id,
            'status' => 'pending',
        ]);

        app(StockRequestApprovalService::class)->approveStockRequest($stockRequest->id, $this->finance->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'action_url' => "/erp/inventory/stock-request?stock_request={$stockRequest->id}",
        ]);
    }

    public function test_each_actor_rejection_uses_its_own_foreign_key(): void
    {
        $financeRequest = $this->pendingRequest('pending_finance');

        $this->actingAs($this->finance)
            ->postJson("/api/erp/procurement/purchase-requests/{$financeRequest->id}/reject", [
                'rejection_reason' => 'Budget unavailable.',
            ])
            ->assertOk();

        $this->assertSame($this->finance->id, $financeRequest->fresh()->rejected_by_user_id);
        $this->assertNull($financeRequest->fresh()->rejected_by_shop_owner_id);

        $ownerRequest = $this->pendingRequest('pending_shop_owner');

        $this->actingAs($this->shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/purchase-requests/{$ownerRequest->id}/reject", [
                'rejection_reason' => 'Not needed.',
            ])
            ->assertOk();

        $this->assertSame($this->shopOwner->id, $ownerRequest->fresh()->rejected_by_shop_owner_id);
        $this->assertNull($ownerRequest->fresh()->rejected_by_user_id);
    }

    public function test_create_and_retired_job_never_skip_approval(): void
    {
        $stockRequest = $this->acceptedStockRequest();
        $response = $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'stock_request_id' => $stockRequest->id,
                'product_name' => $stockRequest->product_name,
                'supplier_id' => $this->supplier->id,
                'inventory_item_id' => $stockRequest->inventory_item_id,
                'requested_size' => $stockRequest->requested_size,
                'requested_color' => $stockRequest->requested_color,
                'quantity' => $stockRequest->quantity_needed,
                'unit_cost' => 50,
                'priority' => $stockRequest->priority,
                'justification' => 'Routine workshop stock.',
                'submit_to_finance' => true,
            ])
            ->assertCreated();

		$this->assertSame(['message', 'data'], array_keys($response->json()));

        $purchaseRequest = PurchaseRequest::findOrFail($response->json('data.id'));
        $this->assertSame('pending_finance', $purchaseRequest->status);

        app(AutoApproveLowValuePRsJob::class)->handle(app(PurchaseRequestService::class));
        $this->assertSame('pending_finance', $purchaseRequest->fresh()->status);
    }

    public function test_create_only_permission_cannot_create_and_submit(): void
    {
        $createOnly = User::factory()->for($this->shopOwner)->create();
        $this->give($createOnly, 'procurement.create_purchase_requests');
        $stockRequest = $this->acceptedStockRequest($createOnly);

        $this->actingAs($createOnly)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'stock_request_id' => $stockRequest->id,
                'product_name' => 'Shoe cleaner',
                'supplier_id' => $this->supplier->id,
                'quantity' => 200,
                'unit_cost' => 100,
                'priority' => 'medium',
                'justification' => 'Create and submit permission check.',
                'submit_to_finance' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('purchase_requests', ['product_name' => 'Shoe cleaner']);
    }

    public function test_http_creation_requires_and_uniquely_links_an_accepted_stock_request(): void
    {
        $payload = [
            'product_name' => 'Shoe laces',
            'supplier_id' => $this->supplier->id,
            'quantity' => 2,
            'unit_cost' => 50,
            'priority' => 'medium',
            'justification' => 'Required stock request linkage.',
        ];

        $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_request_id');

        $stockRequest = $this->acceptedStockRequest();
        $payload = [
            ...$payload,
            'product_name' => $stockRequest->product_name,
            'inventory_item_id' => $stockRequest->inventory_item_id,
            'requested_size' => $stockRequest->requested_size,
            'requested_color' => $stockRequest->requested_color,
            'quantity' => $stockRequest->quantity_needed,
            'priority' => $stockRequest->priority,
        ];
        $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', ['stock_request_id' => $stockRequest->id, ...$payload])
            ->assertCreated();

        $this->assertDatabaseHas('purchase_requests', ['stock_request_id' => $stockRequest->id]);

        $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', ['stock_request_id' => $stockRequest->id, ...$payload])
            ->assertUnprocessable();
    }

    public function test_http_creation_copies_identity_and_total_quantity_from_the_stock_request(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'name' => 'Authoritative shoe',
            'category' => 'shoes',
        ]);
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $inventory->id,
            'requested_by' => $this->requester->id,
            'product_name' => 'Authoritative shoe',
            'quantity_needed' => 200,
            'requested_size' => null,
            'requested_color' => 'Black',
            'priority' => 'high',
            'status' => 'accepted',
        ]);
        $this->assertSame($inventory->id, $stockRequest->inventory_item_id);

        $response = $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'stock_request_id' => $stockRequest->id,
                'product_name' => 'Authoritative shoe',
                'inventory_item_id' => $inventory->id,
                'requested_size' => null,
                'requested_color' => 'Black',
                'supplier_id' => $this->supplier->id,
                'quantity' => 200,
                'unit_cost' => 4100,
                'priority' => 'high',
                'justification' => 'Use the accepted stock request as source.',
            ])
            ->assertCreated();

        $purchaseRequest = PurchaseRequest::findOrFail($response->json('data.id'));
        $this->assertSame($inventory->id, $purchaseRequest->inventory_item_id);
        $this->assertSame('Authoritative shoe', $purchaseRequest->product_name);
        $this->assertSame(200, $purchaseRequest->quantity);
        $this->assertNull($purchaseRequest->requested_size);
        $this->assertSame('Black', $purchaseRequest->requested_color);
        $this->assertSame('high', $purchaseRequest->priority);
        $this->assertSame('820000.00', $purchaseRequest->total_cost);
    }

    public function test_inventory_per_size_request_flows_to_pr_with_physical_total_and_cost(): void
    {
        $this->give($this->requester, 'view-inventory');
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'name' => 'Normalized shoe',
            'category' => 'shoes',
        ]);
        $variant = InventoryColorVariant::create([
            'inventory_item_id' => $inventory->id,
            'color_name' => 'Black',
            'quantity' => 0,
        ]);
        foreach (['3', '5', '7', '9'] as $size) {
            InventorySize::create([
                'inventory_item_id' => $inventory->id,
                'inventory_color_variant_id' => $variant->id,
                'size' => $size,
                'size_system' => 'US',
                'quantity' => 0,
            ]);
        }

        $stockRequest = $this->actingAs($this->requester, 'user')
            ->postJson('/api/erp/inventory/stock-requests', [
                'inventory_item_id' => $inventory->id,
                'quantity_needed' => 50,
                'quantity_basis' => 'per_size',
                'priority' => 'high',
                'requested_size' => '',
                'requested_color' => 'black',
                'notes' => 'Restock all configured sizes.',
            ])
            ->assertCreated()
            ->json('stock_request');

        $this->assertSame(200, $stockRequest['quantity_needed']);
        $source = StockRequestApproval::findOrFail($stockRequest['id']);
        $source->update(['status' => 'accepted']);

        $response = $this->actingAs($this->requester, 'user')
            ->postJson('/api/erp/procurement/purchase-requests', [
                'stock_request_id' => $source->id,
                'product_name' => $stockRequest['product_name'],
                'supplier_id' => $this->supplier->id,
                'quantity' => $stockRequest['quantity_needed'],
                'unit_cost' => 4100,
                'priority' => 'high',
                'justification' => 'Physical quantity from all configured sizes.',
            ])
            ->assertCreated();

        $this->assertSame(200, $response->json('data.quantity'));
        $this->assertSame('820000.00', $response->json('data.total_cost'));
    }

    public function test_available_stock_requests_exclude_any_request_already_linked_to_a_pr(): void
    {
        $consumed = $this->acceptedStockRequest();
        $available = $this->acceptedStockRequest();
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'stock_request_id' => $consumed->id,
            'supplier_id' => $this->supplier->id,
            'requested_by' => $this->requester->id,
            'status' => 'approved',
        ]);

        $this->actingAs($this->requester)
            ->getJson('/api/erp/procurement/stock-requests?status=accepted&available_for_purchase_request=1&per_page=200')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id);
    }

    public function test_legacy_finance_pr_page_redirects_into_the_finance_shell(): void
    {
        $this->give($this->finance, 'access-purchase-request-approval');

        $this->actingAs($this->finance)
            ->get('/finance/purchase-request-approval?purchase_request=42')
            ->assertRedirect('/finance?section=purchase-request-approval&purchase_request=42');
    }

    public function test_pr_cannot_change_accepted_stock_request_details(): void
    {
        $stockRequest = $this->acceptedStockRequest();

        $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'stock_request_id' => $stockRequest->id,
                'product_name' => $stockRequest->product_name . ' (tampered)',
                'supplier_id' => $this->supplier->id,
                'inventory_item_id' => $stockRequest->inventory_item_id,
                'requested_size' => $stockRequest->requested_size,
                'requested_color' => $stockRequest->requested_color,
                'quantity' => $stockRequest->quantity_needed + 1,
                'unit_cost' => 50,
                'priority' => $stockRequest->priority,
                'justification' => 'The approved stock request details must remain unchanged.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_name');
    }

    public function test_inactive_supplier_cannot_be_used_for_a_purchase_request(): void
    {
        $this->supplier->update(['is_active' => false]);
        $stockRequest = $this->acceptedStockRequest();

        $this->actingAs($this->requester)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'stock_request_id' => $stockRequest->id,
                'product_name' => $stockRequest->product_name,
                'supplier_id' => $this->supplier->id,
                'inventory_item_id' => $stockRequest->inventory_item_id,
                'requested_size' => $stockRequest->requested_size,
                'requested_color' => $stockRequest->requested_color,
                'quantity' => $stockRequest->quantity_needed,
                'unit_cost' => 50,
                'priority' => $stockRequest->priority,
                'justification' => 'An inactive supplier must not be selected for new purchases.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_draft_pr_can_be_updated_without_changing_its_stock_request_source(): void
    {
        $stockRequest = $this->acceptedStockRequest();
        $purchaseRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'stock_request_id' => $stockRequest->id,
            'product_name' => $stockRequest->product_name,
            'supplier_id' => $this->supplier->id,
            'inventory_item_id' => $stockRequest->inventory_item_id,
            'requested_size' => $stockRequest->requested_size,
            'requested_color' => $stockRequest->requested_color,
            'quantity' => $stockRequest->quantity_needed,
            'priority' => $stockRequest->priority,
            'requested_by' => $this->requester->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->requester)
            ->putJson("/api/erp/procurement/purchase-requests/{$purchaseRequest->id}", [
                'stock_request_id' => $stockRequest->id,
                'product_name' => $stockRequest->product_name,
                'supplier_id' => $this->supplier->id,
                'inventory_item_id' => $stockRequest->inventory_item_id,
                'requested_size' => $stockRequest->requested_size,
                'requested_color' => $stockRequest->requested_color,
                'quantity' => $stockRequest->quantity_needed,
                'unit_cost' => 75,
                'priority' => $stockRequest->priority,
                'justification' => 'Update cost while preserving approved demand.',
            ])
            ->assertOk();
    }

    private function pendingRequest(string $status): PurchaseRequest
    {
        return PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'requested_by' => $this->requester->id,
            'status' => $status,
        ]);
    }

    private function acceptedStockRequest(?User $requester = null): StockRequestApproval
    {
        $inventory = InventoryItem::factory()->create(['shop_owner_id' => $this->shopOwner->id]);

        return StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $inventory->id,
            'requested_by' => ($requester ?? $this->requester)->id,
            'status' => 'accepted',
        ]);
    }

    private function give(User $user, string $permission): void
    {
        Permission::findOrCreate($permission, 'user');
        $user->givePermissionTo($permission);
    }

    private function setPurchaseRequestApproval(bool $enabled): void
    {
        $settings = ProcurementSettings::firstOrNew([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
        $settings->settings_json = [
            'approval_pages' => [
                'purchase_request_approval' => [
                    'enabled' => $enabled,
                    'limit' => null,
                ],
            ],
        ];
        $settings->save();
    }
}
