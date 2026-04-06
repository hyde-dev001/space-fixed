# Manual POS Job-Order Assignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure manual walk-in POS repairs are assigned to a repairer at creation time, counted in Job Order workload, and removed from POS Manual Queue once assigned.

**Architecture:** Keep manual walk-in creation inside `RepairPosController`, then apply assignment resolution immediately: self-assign when actor is a repairer, otherwise assign least-loaded repairer in same shop with over-limit fallback. Update workload and POS queue filters so assigned REP-POS records move to Job Orders while payment continues via Proceed to POS.

**Tech Stack:** Laravel, Eloquent, Spatie Roles/Permissions, PHPUnit Feature tests, existing POS/Repair workflow APIs

---

## File Structure Map

### Assignment behavior
- Modify: `app/Http/Controllers/Api/RepairPosController.php`

### Workload and queue visibility
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`

### Tests
- Modify: `tests/Feature/RepairPosManualQueueTest.php`
- Create: `tests/Feature/ManualPosJobOrderAssignmentTest.php`

---

### Task 1: Assign manual POS repair ownership at creation

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Create: `tests/Feature/ManualPosJobOrderAssignmentTest.php`
- Test: `tests/Feature/ManualPosJobOrderAssignmentTest.php`

- [ ] **Step 1: Write failing test for repairer self-assignment**

```php
<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualPosJobOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repairer_actor_self_assigns_manual_pos_checkout(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);

        Role::firstOrCreate(['name' => 'Repairer', 'guard_name' => 'web']);
        $repairer->assignRole('Repairer');

        $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-self-assign-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Self Assign Test',
            'walk_in_phone' => '09170000011',
            'manual_repair_subtotal' => 600,
            'manual_service_summary' => 'Manual POS self assign',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 300],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertSame($repairer->id, (int) $repair->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $repair->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ManualPosJobOrderAssignmentTest.php --filter=self_assigns_manual_pos_checkout`
Expected: FAIL (`assigned_repairer_id` is null and/or status is still `pending`).

- [ ] **Step 3: Implement assignment resolution in manual create flow**

```php
// app/Http/Controllers/Api/RepairPosController.php (inside createManualRepairRequestFromPos after RepairRequest::create)
$repair = RepairRequest::create([
    // ... existing payload
    'status' => 'pending',
]);

$this->assignManualPosRepairOwner($repair, $actor, $shopOwnerId);

return $repair->fresh();
```

```php
// app/Http/Controllers/Api/RepairPosController.php (new private methods)
private function assignManualPosRepairOwner(RepairRequest $repair, object $actor, int $shopOwnerId): void
{
    $actorUserId = Auth::guard('user')->id();

    if ($actorUserId) {
        $actorUser = User::query()->find((int) $actorUserId);
        if ($actorUser && method_exists($actorUser, 'hasRole') && $actorUser->hasRole('Repairer')) {
            $repair->forceFill([
                'assigned_repairer_id' => (int) $actorUser->id,
                'assigned_at' => now(),
                'assignment_method' => 'pos_self_assign',
                'assigned_by' => (int) $actorUser->id,
                'status' => 'assigned_to_repairer',
            ])->save();
            return;
        }
    }

    $candidate = $this->resolveLeastLoadedRepairer($shopOwnerId, false)
        ?? $this->resolveLeastLoadedRepairer($shopOwnerId, true);

    if ($candidate) {
        $repair->forceFill([
            'assigned_repairer_id' => (int) $candidate->id,
            'assigned_at' => now(),
            'assignment_method' => 'pos_auto_assign',
            'assigned_by' => (int) ($actorUserId ?: $this->resolveActorAuditUserId()),
            'status' => 'assigned_to_repairer',
        ])->save();
    }
}

private function resolveLeastLoadedRepairer(int $shopOwnerId, bool $ignoreLimit): ?User
{
    $activeStatuses = [
        'assigned_to_repairer',
        'repairer_accepted',
        'pending',
        'received',
        'in_progress',
        'awaiting_parts',
        'ready_for_pickup',
        'waiting_customer_confirmation',
        'confirmed',
        'owner_approval_pending',
        'owner_approved',
        'manager_reviewing',
        'manager_approved',
    ];

    $workloadLimit = (int) (ShopOwner::query()->whereKey($shopOwnerId)->value('repair_workload_limit') ?? 20);

    $query = User::query()
        ->where('shop_owner_id', $shopOwnerId)
        ->where('status', 'active')
        ->whereHas('roles', fn ($q) => $q->where('name', 'Repairer'))
        ->withCount(['assignedRepairs as active_repairs_count' => fn ($q) => $q->whereIn('status', $activeStatuses)]);

    if (!$ignoreLimit) {
        $query->having('active_repairs_count', '<', $workloadLimit);
    }

    return $query->orderBy('active_repairs_count')->orderBy('id')->first();
}
```

- [ ] **Step 4: Re-run the test**

Run: `php artisan test tests/Feature/ManualPosJobOrderAssignmentTest.php --filter=self_assigns_manual_pos_checkout`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPosController.php tests/Feature/ManualPosJobOrderAssignmentTest.php
git commit -m "feat: assign manual POS repairs to repairer owners"
```

---

### Task 2: Add least-loaded auto-assign and over-limit fallback coverage

**Files:**
- Modify: `tests/Feature/ManualPosJobOrderAssignmentTest.php`
- Test: `tests/Feature/ManualPosJobOrderAssignmentTest.php`

- [ ] **Step 1: Write failing test for non-repairer actor auto-assigning least-loaded repairer**

```php
#[Test]
public function non_repairer_actor_assigns_to_least_loaded_repairer(): void
{
    $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

    $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);

    $repairerA = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);
    $repairerB = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);

    Role::firstOrCreate(['name' => 'Repairer', 'guard_name' => 'web']);
    $repairerA->assignRole('Repairer');
    $repairerB->assignRole('Repairer');

    RepairRequest::create([
        'request_id' => 'REP-WL-0001',
        'customer_name' => 'Load A',
        'email' => 'a@example.test',
        'phone' => '09170001000',
        'shoe_type' => 'Sneakers',
        'description' => 'load',
        'shop_owner_id' => $shopOwner->id,
        'assigned_repairer_id' => $repairerA->id,
        'status' => 'in_progress',
        'images' => [],
        'total' => 500,
        'final_total' => 500,
        'payment_policy' => 'deposit_50',
        'payment_policy_snapshot' => 'deposit_50',
        'payment_status' => 'unpaid',
        'payment_status_derived' => 'unpaid',
        'total_paid_amount' => 0,
        'total_refunded_amount' => 0,
    ]);

    $response = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => null,
        'due_type' => 'deposit',
        'idempotency_key' => 'manual-pos-auto-assign-001',
        'customer_type' => 'walk_in',
        'walk_in_name' => 'Auto Assign Test',
        'walk_in_phone' => '09170000022',
        'manual_repair_subtotal' => 700,
        'manual_service_summary' => 'Manual POS auto assign',
        'manual_payment_policy' => 'deposit_50',
        'payment_lines' => [
            ['tender_type' => 'cash', 'amount' => 350],
        ],
    ]);

    $response->assertOk();

    $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
    $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

    $this->assertSame($repairerB->id, (int) $repair->assigned_repairer_id);
    $this->assertSame('assigned_to_repairer', (string) $repair->status);
}
```

- [ ] **Step 2: Write failing test for over-limit fallback assignment**

```php
#[Test]
public function over_limit_fallback_still_assigns_least_loaded_repairer(): void
{
    $shopOwner = ShopOwner::factory()->approved()->create([
        'business_type' => 'repair',
        'repair_workload_limit' => 1,
    ]);

    $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);
    $repairerA = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);
    $repairerB = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);

    Role::firstOrCreate(['name' => 'Repairer', 'guard_name' => 'web']);
    $repairerA->assignRole('Repairer');
    $repairerB->assignRole('Repairer');

    foreach ([$repairerA->id, $repairerB->id] as $idx => $rid) {
        RepairRequest::create([
            'request_id' => 'REP-LIMIT-' . $idx,
            'customer_name' => 'Limit ' . $idx,
            'email' => "limit{$idx}@example.test",
            'phone' => '0917999' . $idx,
            'shoe_type' => 'Boots',
            'description' => 'limit load',
            'shop_owner_id' => $shopOwner->id,
            'assigned_repairer_id' => $rid,
            'status' => 'in_progress',
            'images' => [],
            'total' => 500,
            'final_total' => 500,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
        ]);
    }

    $response = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => null,
        'due_type' => 'deposit',
        'idempotency_key' => 'manual-pos-overlimit-001',
        'customer_type' => 'walk_in',
        'walk_in_name' => 'Overlimit Test',
        'walk_in_phone' => '09170000033',
        'manual_repair_subtotal' => 800,
        'manual_service_summary' => 'Manual POS overlimit fallback',
        'manual_payment_policy' => 'deposit_50',
        'payment_lines' => [
            ['tender_type' => 'cash', 'amount' => 400],
        ],
    ]);

    $response->assertOk();

    $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
    $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

    $this->assertNotNull($repair->assigned_repairer_id);
    $this->assertSame('assigned_to_repairer', (string) $repair->status);
}
```

- [ ] **Step 3: Run tests to verify failure first, then pass with Task 1 implementation complete**

Run: `php artisan test tests/Feature/ManualPosJobOrderAssignmentTest.php`
Expected before implementation completion: FAIL.
Expected after implementation completion: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/ManualPosJobOrderAssignmentTest.php
git commit -m "test: cover auto-assign and over-limit fallback for manual POS repairs"
```

---

### Task 3: Move assigned REP-POS records from POS queue to Job Orders workload

**Files:**
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `tests/Feature/RepairPosManualQueueTest.php`
- Modify: `tests/Feature/ManualPosJobOrderAssignmentTest.php`
- Test: `tests/Feature/RepairPosManualQueueTest.php`

- [ ] **Step 1: Write failing tests for visibility split (Job Orders include assigned REP-POS, queue excludes assigned REP-POS)**

```php
#[Test]
public function manual_queue_excludes_assigned_rep_pos_records(): void
{
    $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);
    Role::firstOrCreate(['name' => 'Repairer', 'guard_name' => 'web']);
    $repairer->assignRole('Repairer');

    RepairRequest::create([
        'request_id' => 'REP-POS-20260406-0099',
        'customer_name' => 'Assigned Queue Test',
        'email' => 'assigned@example.test',
        'phone' => '09170000999',
        'shoe_type' => 'Walk-in',
        'description' => 'assigned queue exclusion',
        'shop_owner_id' => $shopOwner->id,
        'assigned_repairer_id' => $repairer->id,
        'manual_pos_queue_enabled' => true,
        'status' => 'assigned_to_repairer',
        'images' => [],
        'total' => 500,
        'final_total' => 500,
        'payment_policy' => 'deposit_50',
        'payment_policy_snapshot' => 'deposit_50',
        'payment_status' => 'unpaid',
        'payment_status_derived' => 'unpaid',
        'total_paid_amount' => 0,
        'total_refunded_amount' => 0,
    ]);

    $response = $this->actingAs($user, 'user')->getJson('/api/repair-pos/manual-queue');
    $response->assertOk();
    $this->assertCount(0, $response->json('data'));
}
```

```php
#[Test]
public function shop_owner_workload_includes_assigned_rep_pos_records(): void
{
    $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id, 'status' => 'active']);

    Role::firstOrCreate(['name' => 'Repairer', 'guard_name' => 'web']);
    $repairer->assignRole('Repairer');

    $repair = RepairRequest::create([
        'request_id' => 'REP-POS-20260406-0100',
        'customer_name' => 'Workload Inclusion Test',
        'email' => 'workload@example.test',
        'phone' => '09170001000',
        'shoe_type' => 'Walk-in',
        'description' => 'workload inclusion',
        'shop_owner_id' => $shopOwner->id,
        'assigned_repairer_id' => $repairer->id,
        'manual_pos_queue_enabled' => true,
        'status' => 'assigned_to_repairer',
        'images' => [],
        'total' => 500,
        'final_total' => 500,
        'payment_policy' => 'deposit_50',
        'payment_policy_snapshot' => 'deposit_50',
        'payment_status' => 'unpaid',
        'payment_status_derived' => 'unpaid',
        'total_paid_amount' => 0,
        'total_refunded_amount' => 0,
    ]);

    $response = $this->actingAs($shopOwner, 'shop_owner')->getJson('/api/shop-owner/repairs');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

    $this->assertContains((int) $repair->id, $ids);
}
```

- [ ] **Step 2: Run tests to confirm failure**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php tests/Feature/ManualPosJobOrderAssignmentTest.php`
Expected: FAIL on queue/workload visibility assertions.

- [ ] **Step 3: Implement queue exclusion for assigned records and workload inclusion logic**

```php
// app/Http/Controllers/Api/RepairPosController.php (inside listManualQueue query)
$rows = RepairRequest::query()
    ->where('shop_owner_id', $shopOwnerId)
    ->where('manual_pos_queue_enabled', true)
    ->where('request_id', 'like', 'REP-POS-%')
    ->whereNull('assigned_repairer_id')
    ->whereIn('status', ['pending', 'received', 'in_progress', 'ready_for_pickup'])
    // ...existing filters
```

```php
// app/Http/Controllers/Api/RepairWorkflowController.php (replace REP-POS exclusion closure)
$jobOrderVisibleRepairs = static function ($query): void {
    $query->where(function ($inner) {
        $inner->whereNull('request_id')
            ->orWhere('request_id', 'not like', 'REP-POS-%')
            ->orWhere(function ($manualPos) {
                $manualPos->where('request_id', 'like', 'REP-POS-%')
                    ->whereNotNull('assigned_repairer_id');
            });
    });
};

// Apply ->where($jobOrderVisibleRepairs) in both shop-owner and repairer workload queries.
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php tests/Feature/ManualPosJobOrderAssignmentTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPosController.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/RepairPosManualQueueTest.php tests/Feature/ManualPosJobOrderAssignmentTest.php
git commit -m "feat: route assigned manual POS repairs into job-order workload"
```

---

### Task 4: Regression verification for payment and repair flow

**Files:**
- Test: `tests/Feature/RepairPosPaymentFlowTest.php`
- Test: `tests/Feature/RepairMaterialCompletionVarianceTest.php`

- [ ] **Step 1: Run targeted regression suite**

Run: `php artisan test --filter=RepairPosPaymentFlowTest`
Expected: PASS.

- [ ] **Step 2: Run material completion guard regression**

Run: `php artisan test --filter=RepairMaterialCompletionVarianceTest`
Expected: PASS.

- [ ] **Step 3: Run new assignment and visibility tests as final gate**

Run: `php artisan test tests/Feature/ManualPosJobOrderAssignmentTest.php tests/Feature/RepairPosManualQueueTest.php`
Expected: PASS.

- [ ] **Step 4: Commit verification notes if repository workflow requires test-log capture**

```bash
# only if your branch policy requires a verification artifact file
git add <verification-artifact-file>
git commit -m "chore: record verification for manual POS job-order assignment"
```

---

## Self-Review

1. **Spec coverage:**
- Repairer self-assignment: Task 1.
- Non-repairer least-loaded assignment: Task 2.
- Over-limit fallback assignment: Task 2.
- Job Orders include assigned manual POS repairs: Task 3.
- POS queue excludes assigned manual POS repairs: Task 3.
- Regression safety for payment/material flow: Task 4.

2. **Placeholder scan:**
- No `TODO`/`TBD` steps.
- All code-changing steps include concrete snippets.
- All run steps include explicit commands and expected outcomes.

3. **Type/field consistency:**
- Uses existing `repair_requests` fields: `assigned_repairer_id`, `assigned_at`, `assignment_method`, `assigned_by`, `manual_pos_queue_enabled`, `status`.
- Uses current guards/roles pattern and same workload status vocabulary as existing controllers.
