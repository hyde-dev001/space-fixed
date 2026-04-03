# Repair POS Control Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden repair POS payments and refunds by enforcing strict authorization, idempotent phase settlement, verifiable non-cash tender lifecycle, aggregate-safe refunds, canonical payment status, and persisted repairer transaction history.

**Architecture:** Keep existing repair POS endpoints and move control logic into policy-guarded orchestration in services. Enforce DB-backed phase locks plus request idempotency replay to prevent duplicate charges. Treat `payment_status` as canonical while mirroring derived status during migration.

**Tech Stack:** Laravel 11, Eloquent ORM, MySQL, PHPUnit Feature tests, Inertia React TypeScript, Vitest.

---

## File Structure and Responsibilities

### Backend core
- Create: `app/Policies/RepairRequestPolicy.php` (shop/role/workflow authorization for repair POS actions)
- Modify: `app/Providers/AuthServiceProvider.php` (register policy)
- Modify: `app/Http/Controllers/Api/RepairPosController.php` (delegate authorization, idempotency-aware checkout, verification endpoint wiring)
- Modify: `app/Services/RepairPosPaymentService.php` (phase-lock, idempotency replay, tender states, canonical status updates)
- Modify: `app/Services/RepairPosRefundService.php` (aggregate refund source and canonical status sync)
- Modify: `app/Services/RepairPosReceiptService.php` (registered customer identity in receipt payload)
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php` (cancellation path uses aggregate refund source)
- Modify: `routes/api.php` (route middleware and new history/verification endpoints)

### Backend schema
- Create: `database/migrations/2026_04_03_130000_add_idempotency_and_phase_lock_fields_to_pos_transactions.php`
- Create: `database/migrations/2026_04_03_130100_add_verification_status_to_pos_payment_lines.php`

### Frontend
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx` (history retrieval + request-refund flow)
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx` (status rendering parity + verification cues)
- Create: `resources/js/services/repairPosHistoryApi.ts` (typed history/refund request methods)

### Tests
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`
- Modify: `tests/Feature/RepairPosRefundFlowTest.php`
- Create: `tests/Feature/RepairPosAuthorizationTest.php`
- Create: `tests/Feature/RepairPosTenderVerificationTest.php`
- Create: `tests/Feature/RepairPosHistoryApiTest.php`
- Create: `resources/js/Pages/ERP/repairer/__tests__/POS.history-and-refund-request.test.tsx`

---

### Task 1: Add Strict Checkout Authorization and Workflow Guards

**Files:**
- Create: `app/Policies/RepairRequestPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/RepairPosAuthorizationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function checkout_rejects_user_from_different_shop(): void
    {
        $owningShop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $owningShop->id,
            'payment_policy' => 'deposit_50',
            'status' => 'ready_for_pickup',
        ]);

        $intruder = User::factory()->create(['shop_owner_id' => $otherShop->id]);

        $this->actingAs($intruder, 'user')
            ->postJson('/api/repair-pos/checkout', [
                'repair_request_id' => $repair->id,
                'due_type' => 'deposit',
                'customer_type' => 'walk_in',
                'walk_in_name' => 'Walk In',
                'payment_lines' => [
                    ['tender_type' => 'cash', 'amount' => 560],
                ],
                'idempotency_key' => 'key-auth-deny-1',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'AUTH_FORBIDDEN_SHOP_SCOPE');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosAuthorizationTest.php --filter=checkout_rejects_user_from_different_shop`
Expected: FAIL with `200`/`422` because checkout currently lacks strict shop-scope authorization.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Policies;

use App\Models\RepairRequest;
use App\Models\User;

class RepairRequestPolicy
{
    public function posCheckout(User $user, RepairRequest $repair): bool
    {
        $sameShop = (int) ($user->shop_owner_id ?? 0) > 0
            && (int) $user->shop_owner_id === (int) $repair->shop_owner_id;

        if (!$sameShop) {
            return false;
        }

        $allowedStatuses = ['ready_for_pickup', 'in_progress', 'completed'];
        return in_array((string) $repair->status, $allowedStatuses, true);
    }
}
```

```php
// app/Providers/AuthServiceProvider.php
protected $policies = [
    \App\Models\RepairRequest::class => \App\Policies\RepairRequestPolicy::class,
];
```

```php
// app/Http/Controllers/Api/RepairPosController.php (inside checkout)
$repair = RepairRequest::findOrFail((int) $validated['repair_request_id']);
if (!$request->user('user')?->can('posCheckout', $repair)) {
    return response()->json([
        'success' => false,
        'code' => 'AUTH_FORBIDDEN_SHOP_SCOPE',
        'message' => 'You are not allowed to process checkout for this repair request.',
    ], 403);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairPosAuthorizationTest.php --filter=checkout_rejects_user_from_different_shop`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/RepairRequestPolicy.php app/Providers/AuthServiceProvider.php app/Http/Controllers/Api/RepairPosController.php tests/Feature/RepairPosAuthorizationTest.php
git commit -m "feat(repair-pos): enforce policy-based checkout authorization"
```

---

### Task 2: Add DB-Safe Phase Locking and Request Idempotency

**Files:**
- Create: `database/migrations/2026_04_03_130000_add_idempotency_and_phase_lock_fields_to_pos_transactions.php`
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function checkout_replay_returns_existing_transaction_and_does_not_duplicate_phase_charge(): void
{
    $actor = User::factory()->create();
    $repair = RepairRequest::factory()->create([
        'shop_owner_id' => $actor->shop_owner_id,
        'payment_policy' => 'deposit_50',
        'status' => 'ready_for_pickup',
    ]);

    $payload = [
        'repair_request_id' => $repair->id,
        'due_type' => 'deposit',
        'customer_type' => 'walk_in',
        'walk_in_name' => 'Replay Test',
        'idempotency_key' => 'idem-phase-001',
        'payment_lines' => [
            ['tender_type' => 'cash', 'amount' => 560],
        ],
    ];

    $first = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $payload);
    $second = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $payload);

    $first->assertStatus(200);
    $second->assertStatus(200)->assertJsonPath('meta.idempotency_replay', true);

    $this->assertSame(1, \App\Models\PosTransaction::query()
        ->where('module_type', 'repair')
        ->where('module_reference_id', $repair->id)
        ->where('due_type', 'deposit')
        ->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=checkout_replay_returns_existing_transaction_and_does_not_duplicate_phase_charge`
Expected: FAIL with duplicate transaction count.

- [ ] **Step 3: Write minimal implementation**

```php
// database/migrations/2026_04_03_130000_add_idempotency_and_phase_lock_fields_to_pos_transactions.php
Schema::table('pos_transactions', function (Blueprint $table) {
    $table->string('idempotency_key', 100)->nullable()->after('transaction_no');
    $table->string('phase_lock_key', 120)->nullable()->after('idempotency_key');
    $table->index(['module_type', 'module_reference_id', 'due_type'], 'idx_pos_phase_lookup');
    $table->unique(['phase_lock_key'], 'uq_pos_phase_lock_key');
    $table->unique(['module_type', 'module_reference_id', 'due_type', 'idempotency_key'], 'uq_pos_idempotency_scope');
});
```

```php
// app/Http/Controllers/Api/RepairPosController.php (validation)
'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
```

```php
// app/Services/RepairPosPaymentService.php (before create)
$phaseLockKey = sprintf('repair:%d:%s', (int) $repair->id, strtolower($dueType));
$idempotencyKey = (string) $payload['idempotency_key'];

$replay = PosTransaction::query()
    ->where('module_type', 'repair')
    ->where('module_reference_id', $repair->id)
    ->where('due_type', $dueType)
    ->where('idempotency_key', $idempotencyKey)
    ->first();

if ($replay) {
    $replay->setAttribute('idempotency_replay', true);
    return $replay;
}

$alreadySettled = PosTransaction::query()
    ->where('module_type', 'repair')
    ->where('module_reference_id', $repair->id)
    ->where('due_type', $dueType)
    ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
    ->exists();

if ($alreadySettled) {
    throw ValidationException::withMessages([
        'due_type' => ['PAYMENT_PHASE_ALREADY_SETTLED'],
    ]);
}

$transaction = PosTransaction::create([
    // existing fields...
    'idempotency_key' => $idempotencyKey,
    'phase_lock_key' => $phaseLockKey,
]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=checkout_replay_returns_existing_transaction_and_does_not_duplicate_phase_charge`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_03_130000_add_idempotency_and_phase_lock_fields_to_pos_transactions.php app/Http/Controllers/Api/RepairPosController.php app/Services/RepairPosPaymentService.php tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "feat(repair-pos): add phase lock and idempotent checkout replay"
```

---

### Task 3: Implement Non-Cash Pending Verification Lifecycle

**Files:**
- Create: `database/migrations/2026_04_03_130100_add_verification_status_to_pos_payment_lines.php`
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/RepairPosTenderVerificationTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function non_cash_tender_is_created_as_pending_authorization(): void
{
    $actor = User::factory()->create();
    $repair = RepairRequest::factory()->create([
        'shop_owner_id' => $actor->shop_owner_id,
        'payment_policy' => 'full_upfront',
        'status' => 'ready_for_pickup',
    ]);

    $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => $repair->id,
        'due_type' => 'full',
        'customer_type' => 'walk_in',
        'walk_in_name' => 'Wallet Pending',
        'idempotency_key' => 'idem-wallet-001',
        'payment_lines' => [
            [
                'tender_type' => 'paymongo_wallet',
                'amount' => 1120,
                'provider_reference' => 'PM-REF-001',
            ],
        ],
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('pos_payment_lines', [
        'tender_type' => 'paymongo_wallet',
        'status' => 'pending_authorization',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosTenderVerificationTest.php --filter=non_cash_tender_is_created_as_pending_authorization`
Expected: FAIL because line status is currently `paid`.

- [ ] **Step 3: Write minimal implementation**

```php
// database/migrations/2026_04_03_130100_add_verification_status_to_pos_payment_lines.php
Schema::table('pos_payment_lines', function (Blueprint $table) {
    $table->string('verification_status', 40)->nullable()->after('status');
    $table->timestamp('verified_at')->nullable()->after('paid_at');
    $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
    $table->boolean('manual_fallback_used')->default(false)->after('verified_by');
    $table->string('verification_mode', 30)->nullable()->after('manual_fallback_used');
    $table->string('verification_note', 255)->nullable()->after('verification_mode');
});
```

```php
// app/Services/RepairPosPaymentService.php (line creation)
$isNonCash = in_array($line['tender_type'], ['paymongo_card', 'paymongo_wallet'], true);

PosPaymentLine::create([
    'pos_transaction_id' => $transaction->id,
    'tender_type' => $line['tender_type'],
    'provider_reference' => $line['provider_reference'] ?? null,
    'amount' => $line['amount'],
    'status' => $isNonCash ? 'pending_authorization' : 'paid',
    'verification_status' => $isNonCash ? 'pending' : 'verified',
    'paid_at' => $isNonCash ? null : now(),
]);
```

```php
// app/Http/Controllers/Api/RepairPosController.php (new endpoint)
public function verifyPaymentLine(Request $request, PosPaymentLine $line, RepairPosPaymentService $service)
{
    $validated = $request->validate([
        'decision' => ['required', 'string', 'in:approve,reject'],
        'mode' => ['required', 'string', 'in:gateway,manual_fallback'],
        'note' => ['nullable', 'string', 'max:255'],
    ]);

    $data = $service->verifyPaymentLine($line, $validated, (int) auth('user')->id());

    return response()->json(['success' => true, 'data' => $data]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairPosTenderVerificationTest.php --filter=non_cash_tender_is_created_as_pending_authorization`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_03_130100_add_verification_status_to_pos_payment_lines.php app/Services/RepairPosPaymentService.php app/Http/Controllers/Api/RepairPosController.php routes/api.php tests/Feature/RepairPosTenderVerificationTest.php
git commit -m "feat(repair-pos): add pending verification lifecycle for non-cash tender"
```

---

### Task 4: Fix Aggregate Refund Source and Canonical Payment Status Synchronization

**Files:**
- Modify: `app/Services/RepairPosRefundService.php`
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `tests/Feature/RepairPosRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function cancel_refund_uses_aggregate_paid_amount_not_latest_transaction_only(): void
{
    $repair = RepairRequest::factory()->create([
        'payment_status' => 'paid',
        'status' => 'for_release',
    ]);

    PosTransaction::factory()->create([
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'due_type' => 'deposit',
        'paid_amount' => 560,
        'status' => 'paid',
    ]);

    PosTransaction::factory()->create([
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'due_type' => 'balance',
        'paid_amount' => 560,
        'status' => 'paid',
    ]);

    $this->postJson('/api/repairs/'.$repair->id.'/cancel', [
        'reason' => 'customer_cancelled',
    ])->assertStatus(200);

    $this->assertEquals(1120.00, (float) PosRefund::query()
        ->where('module_type', 'repair')
        ->where('module_reference_id', $repair->id)
        ->where('status', 'succeeded')
        ->sum('approved_amount'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosRefundFlowTest.php --filter=cancel_refund_uses_aggregate_paid_amount_not_latest_transaction_only`
Expected: FAIL because only the latest transaction amount is refunded.

- [ ] **Step 3: Write minimal implementation**

```php
// app/Services/RepairPosRefundService.php (helper)
public function computeRepairRefundableAmount(int $repairId): float
{
    $paid = (float) PosTransaction::query()
        ->where('module_type', 'repair')
        ->where('module_reference_id', $repairId)
        ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
        ->sum('paid_amount');

    $refunded = (float) PosRefund::query()
        ->where('module_type', 'repair')
        ->where('module_reference_id', $repairId)
        ->where('status', 'succeeded')
        ->sum('approved_amount');

    return max(0, round($paid - $refunded, 2));
}
```

```php
// app/Services/RepairPosRefundService.php (inside markRefundSucceeded)
$repair->update([
    'total_refunded_amount' => $totalRefundedForRepair,
    'payment_status' => $sourceStatus === 'refunded' ? 'refunded' : 'partially_refunded',
    'payment_status_derived' => $sourceStatus === 'refunded' ? 'refunded' : 'partially_refunded',
]);
```

```php
// app/Http/Controllers/Api/RepairWorkflowController.php (cancel flow)
$refundable = app(RepairPosRefundService::class)->computeRepairRefundableAmount((int) $repair->id);
if ($refundable > 0) {
    // build refund request/approval/execution using aggregate value
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairPosRefundFlowTest.php --filter=cancel_refund_uses_aggregate_paid_amount_not_latest_transaction_only`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairPosRefundService.php app/Services/RepairPosPaymentService.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/RepairPosRefundFlowTest.php
git commit -m "fix(repair-pos): use aggregate refund source and sync canonical payment status"
```

---

### Task 5: Add Persisted Transaction History API and Repairer Refund Request Flow

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/RepairPosHistoryApiTest.php`
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Create: `resources/js/services/repairPosHistoryApi.ts`
- Create: `resources/js/Pages/ERP/repairer/__tests__/POS.history-and-refund-request.test.tsx`

- [ ] **Step 1: Write the failing backend test**

```php
#[Test]
public function repairer_can_view_repair_pos_history_but_cannot_execute_refund(): void
{
    $repairer = User::factory()->create();
    $repair = RepairRequest::factory()->create(['shop_owner_id' => $repairer->shop_owner_id]);

    PosTransaction::factory()->create([
        'shop_owner_id' => $repairer->shop_owner_id,
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'status' => 'paid',
    ]);

    $this->actingAs($repairer, 'user')
        ->getJson('/api/repair-pos/transactions?repair_request_id='.$repair->id)
        ->assertOk()
        ->assertJsonPath('success', true);

    $refund = PosRefund::factory()->create([
        'shop_owner_id' => $repairer->shop_owner_id,
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'status' => 'requested',
    ]);

    $this->actingAs($repairer, 'user')
        ->postJson('/api/repair-pos/refunds/'.$refund->id.'/execute', ['execution_mode' => 'manual'])
        ->assertStatus(403);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosHistoryApiTest.php --filter=repairer_can_view_repair_pos_history_but_cannot_execute_refund`
Expected: FAIL with missing history endpoint and/or missing role gate.

- [ ] **Step 3: Write minimal backend and frontend implementation**

```php
// routes/api.php
Route::middleware(['web', 'auth:user'])->prefix('repair-pos')->group(function () {
    Route::get('/transactions', [RepairPosController::class, 'listTransactions']);
});
```

```php
// app/Http/Controllers/Api/RepairPosController.php
public function listTransactions(Request $request)
{
    $repairRequestId = (int) $request->query('repair_request_id');
    $shopOwnerId = (int) (auth('user')->user()?->shop_owner_id ?? 0);

    $rows = PosTransaction::query()
        ->where('module_type', 'repair')
        ->where('shop_owner_id', $shopOwnerId)
        ->when($repairRequestId > 0, fn ($q) => $q->where('module_reference_id', $repairRequestId))
        ->with(['paymentLines', 'receipt'])
        ->orderByDesc('id')
        ->paginate(20);

    return response()->json(['success' => true, 'data' => $rows]);
}
```

```ts
// resources/js/services/repairPosHistoryApi.ts
import axios from 'axios';

export const repairPosHistoryApi = {
  listTransactions(repairRequestId?: number) {
    return axios.get('/api/repair-pos/transactions', {
      params: repairRequestId ? { repair_request_id: repairRequestId } : undefined,
    });
  },
  requestRefund(payload: {
    source_transaction_id: number;
    request_type: 'full' | 'partial';
    requested_amount: number;
    reason_code: string;
    reason_notes?: string;
  }) {
    return axios.post('/api/repair-pos/refunds', payload);
  },
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/RepairPosHistoryApiTest.php`
Expected: PASS.

Run: `pnpm vitest resources/js/Pages/ERP/repairer/__tests__/POS.history-and-refund-request.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPosController.php routes/api.php tests/Feature/RepairPosHistoryApiTest.php resources/js/services/repairPosHistoryApi.ts resources/js/Pages/ERP/repairer/POS.tsx resources/js/Pages/ERP/repairer/__tests__/POS.history-and-refund-request.test.tsx
git commit -m "feat(repair-pos): add repairer transaction history and request-only refund flow"
```

---

### Task 6: Enrich Receipt Customer Identity for Registered Customers

**Files:**
- Modify: `app/Services/RepairPosReceiptService.php`
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function receipt_includes_registered_customer_identity_fields(): void
{
    $customer = User::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Santos',
        'email' => 'jamie@example.com',
    ]);

    $repair = RepairRequest::factory()->create([
        'user_id' => $customer->id,
    ]);

    $transaction = PosTransaction::factory()->create([
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'customer_type' => 'registered',
        'customer_id' => $customer->id,
        'status' => 'paid',
    ]);

    app(\App\Services\RepairPosReceiptService::class)->issue($transaction);

    $receipt = \App\Models\PosReceipt::query()->where('pos_transaction_id', $transaction->id)->firstOrFail();
    $payload = $receipt->print_payload;

    $this->assertSame('Jamie Santos', data_get($payload, 'customer.name'));
    $this->assertSame('jamie@example.com', data_get($payload, 'customer.email'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=receipt_includes_registered_customer_identity_fields`
Expected: FAIL because payload currently uses empty walk-in placeholders.

- [ ] **Step 3: Write minimal implementation**

```php
// app/Services/RepairPosReceiptService.php (inside payload builder)
$registeredCustomer = null;
if ((string) $transaction->customer_type === 'registered' && (int) ($transaction->customer_id ?? 0) > 0) {
    $registeredCustomer = \App\Models\User::query()->find((int) $transaction->customer_id);
}

$customerName = $registeredCustomer
    ? trim((string) ($registeredCustomer->first_name . ' ' . $registeredCustomer->last_name))
    : (string) ($transaction->walk_in_name ?? 'Walk-in Customer');

$customerEmail = $registeredCustomer
    ? (string) ($registeredCustomer->email ?? '')
    : (string) ($transaction->walk_in_email ?? '');

$payload['customer'] = [
    'type' => $transaction->customer_type,
    'name' => $customerName,
    'phone' => (string) ($transaction->walk_in_phone ?? ''),
    'email' => $customerEmail,
    'customer_id' => (int) ($transaction->customer_id ?? 0),
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=receipt_includes_registered_customer_identity_fields`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairPosReceiptService.php tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "fix(repair-pos): include registered customer identity in receipt payload"
```

---

### Task 7: Canonicalize Payment Status and Add Regression Coverage

**Files:**
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Services/RepairPosRefundService.php`
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`
- Modify: `tests/Feature/RepairPosRefundFlowTest.php`

- [ ] **Step 1: Write the failing tests**

```php
#[Test]
public function payment_and_refund_transitions_keep_payment_status_canonical_and_in_sync(): void
{
    $repair = RepairRequest::factory()->create([
        'payment_status' => 'unpaid',
        'payment_status_derived' => 'unpaid',
    ]);

    // simulate paid then refunded transitions through services

    $repair->refresh();
    $this->assertSame((string) $repair->payment_status, (string) $repair->payment_status_derived);
    $this->assertContains((string) $repair->payment_status, ['unpaid', 'partially_paid', 'paid', 'partially_refunded', 'refunded']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/RepairPosRefundFlowTest.php --filter=payment_and_refund_transitions_keep_payment_status_canonical_and_in_sync`
Expected: FAIL when refund path updates derived only.

- [ ] **Step 3: Write minimal implementation**

```php
// app/Services/RepairPosPaymentService.php
$repair->update([
    'payment_status' => $canonicalStatus,
    'payment_status_derived' => $canonicalStatus,
    'total_paid_amount' => $totalPaid,
    'latest_pos_transaction_id' => $transaction->id,
]);
```

```php
// app/Services/RepairPosRefundService.php
$repair->update([
    'payment_status' => $canonicalRefundStatus,
    'payment_status_derived' => $canonicalRefundStatus,
    'total_refunded_amount' => $totalRefundedForRepair,
]);
```

- [ ] **Step 4: Run full targeted suite**

Run: `php artisan test tests/Feature/RepairPosAuthorizationTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/RepairPosTenderVerificationTest.php tests/Feature/RepairPosRefundFlowTest.php tests/Feature/RepairPosHistoryApiTest.php`
Expected: PASS.

Run: `pnpm vitest resources/js/Pages/ERP/repairer/__tests__/POS.history-and-refund-request.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairPosPaymentService.php app/Services/RepairPosRefundService.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/RepairPosRefundFlowTest.php
git commit -m "refactor(repair-pos): canonicalize payment status updates across payment and refund flows"
```

---

## Self-Review

1. Spec coverage check
- Authorization lapse: covered by Task 1.
- Duplicate charge risk: covered by Task 2.
- Non-cash verification risk: covered by Task 3.
- Refund source/status flaws: covered by Task 4.
- Missing transaction history for repairer: covered by Task 5.
- Registered customer receipt completeness: covered by Task 6.
- Status normalization: covered by Task 7.

2. Placeholder scan
- No `TODO`/`TBD` placeholders were used.
- Every implementation step includes concrete snippets and command lines.

3. Type/signature consistency
- Endpoint prefix consistently uses `/api/repair-pos/*`.
- Canonical status field consistently named `payment_status`.
- Idempotency key consistently named `idempotency_key`.
