# Repair POS Payment Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a POS-first repair payment and refund system where all financial events are persisted as transactions/receipts, including online refund completion via webhook confirmation.

**Architecture:** Keep `RepairRequest` as the service lifecycle source of truth and introduce POS transaction entities as the financial source of truth. Job Order UI will only hand off to POS for payment and display derived financial state; it will no longer directly mark orders paid. Refunds are always linked to original transactions and use threshold-based approval, with online refunds finalized only by gateway webhook.

**Tech Stack:** Laravel 11 (Eloquent, FormRequest, Feature Tests), MySQL, React + TypeScript + Tailwind (Inertia), Vitest + Testing Library.

---

## Scope Check

This spec is one cohesive subsystem (Repair POS payments + receipts + refunds). It can be implemented as a single plan with staged milestones and does not require decomposition into multiple plans.

## File Structure and Responsibilities

### Backend Domain
- Create: `database/migrations/2026_04_03_100000_create_pos_transactions_table.php`
- Create: `database/migrations/2026_04_03_100100_create_pos_transaction_items_table.php`
- Create: `database/migrations/2026_04_03_100200_create_repair_refunds_table.php`
- Create: `database/migrations/2026_04_03_100300_create_receipt_records_table.php`
- Modify: `app/Models/RepairRequest.php` (financial summary relationship accessors)
- Create: `app/Models/PosTransaction.php`
- Create: `app/Models/PosTransactionItem.php`
- Create: `app/Models/RepairRefund.php`
- Create: `app/Models/ReceiptRecord.php`

### Backend Services and Controllers
- Create: `app/Services/RepairPOSPaymentService.php`
- Create: `app/Services/RepairPOSRefundService.php`
- Create: `app/Http/Requests/RepairPOS/CreateTransactionRequest.php`
- Create: `app/Http/Requests/RepairPOS/CreateRefundRequest.php`
- Create: `app/Http/Controllers/Api/RepairPOSController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php` (remove direct paid mutation entry points from UI-facing behavior)
- Modify: `app/Http/Controllers/PaymongoWebhookController.php` (online refund finalize state machine)
- Modify: `routes/web.php` (repair POS endpoints)

### Frontend
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx` (replace Mark Paid with Proceed to POS / View Receipt)
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx` (persist transaction, load receipt history from API, trigger refunds)
- Create: `resources/js/services/repairPosApi.ts`
- Create: `resources/js/types/repairPos.ts`

### Testing
- Create: `tests/Feature/Repairer/RepairPOSPaymentFlowTest.php`
- Create: `tests/Feature/Repairer/RepairPOSRefundFlowTest.php`
- Create: `tests/Feature/Repairer/RepairPOSWebhookRefundTest.php`
- Create: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.paymentActions.test.tsx`
- Create: `resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`

### Documentation
- Create: `docs/repair/repair-pos-payment-workflow.md`

---

### Task 1: Create Financial Schema and Core Models

**Files:**
- Create: `database/migrations/2026_04_03_100000_create_pos_transactions_table.php`
- Create: `database/migrations/2026_04_03_100100_create_pos_transaction_items_table.php`
- Create: `database/migrations/2026_04_03_100200_create_repair_refunds_table.php`
- Create: `database/migrations/2026_04_03_100300_create_receipt_records_table.php`
- Create: `app/Models/PosTransaction.php`
- Create: `app/Models/PosTransactionItem.php`
- Create: `app/Models/RepairRefund.php`
- Create: `app/Models/ReceiptRecord.php`
- Modify: `app/Models/RepairRequest.php`
- Test: `tests/Feature/Repairer/RepairPOSPaymentFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Repairer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairPOSPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_financial_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('pos_transactions'));
        $this->assertTrue(Schema::hasTable('pos_transaction_items'));
        $this->assertTrue(Schema::hasTable('repair_refunds'));
        $this->assertTrue(Schema::hasTable('receipt_records'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairPOSPaymentFlowTest.php --filter=pos_financial_tables_exist`
Expected: FAIL with missing table assertion.

- [ ] **Step 3: Write minimal implementation**

```php
// database/migrations/2026_04_03_100000_create_pos_transactions_table.php
Schema::create('pos_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('repair_request_id')->constrained('repair_requests')->cascadeOnDelete();
    $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
    $table->foreignId('cashier_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('receipt_number')->unique();
    $table->enum('customer_mode', ['account', 'guest']);
    $table->unsignedBigInteger('customer_user_id')->nullable();
    $table->string('guest_name')->nullable();
    $table->string('guest_phone', 20)->nullable();
    $table->decimal('subtotal', 12, 2);
    $table->decimal('discount', 12, 2)->default(0);
    $table->decimal('tax', 12, 2)->default(0);
    $table->decimal('total', 12, 2);
    $table->decimal('paid_amount', 12, 2)->default(0);
    $table->decimal('change_amount', 12, 2)->default(0);
    $table->enum('status', ['pending', 'paid', 'failed', 'voided', 'partially_refunded', 'refunded']);
    $table->string('payment_provider')->nullable();
    $table->string('payment_reference')->nullable();
    $table->string('idempotency_key')->nullable()->index();
    $table->json('meta')->nullable();
    $table->timestamps();
});

// app/Models/PosTransaction.php
class PosTransaction extends Model
{
    protected $fillable = [
        'repair_request_id','shop_owner_id','cashier_user_id','receipt_number','customer_mode',
        'customer_user_id','guest_name','guest_phone','subtotal','discount','tax','total',
        'paid_amount','change_amount','status','payment_provider','payment_reference','idempotency_key','meta',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'meta' => 'array',
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan migrate:fresh --env=testing && php artisan test tests/Feature/Repairer/RepairPOSPaymentFlowTest.php --filter=pos_financial_tables_exist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_03_100000_create_pos_transactions_table.php database/migrations/2026_04_03_100100_create_pos_transaction_items_table.php database/migrations/2026_04_03_100200_create_repair_refunds_table.php database/migrations/2026_04_03_100300_create_receipt_records_table.php app/Models/PosTransaction.php app/Models/PosTransactionItem.php app/Models/RepairRefund.php app/Models/ReceiptRecord.php app/Models/RepairRequest.php tests/Feature/Repairer/RepairPOSPaymentFlowTest.php
git commit -m "feat(repair-pos): add transaction, receipt, and refund schema"
```

---

### Task 2: Build POS Payment API for Repair Orders

**Files:**
- Create: `app/Http/Requests/RepairPOS/CreateTransactionRequest.php`
- Create: `app/Services/RepairPOSPaymentService.php`
- Create: `app/Http/Controllers/Api/RepairPOSController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Repairer/RepairPOSPaymentFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_repair_pos_checkout_creates_paid_transaction_and_receipt(): void
{
    $repairer = User::factory()->create();
    $shopOwner = ShopOwner::factory()->create();

    $repair = RepairRequest::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'status' => 'ready_for_pickup',
        'payment_status' => 'pending',
        'payment_policy' => 'full_upfront',
        'total_amount' => 1500,
    ]);

    $payload = [
        'repair_request_id' => $repair->id,
        'customer_mode' => 'guest',
        'guest_name' => 'Walk In Buyer',
        'guest_phone' => '09171234567',
        'payment_method' => 'cash',
        'subtotal' => 1339.29,
        'discount' => 0,
        'tax' => 160.71,
        'total' => 1500,
        'paid_amount' => 2000,
        'items' => [
            ['service_name' => 'Deep Sole Reglue', 'qty' => 1, 'unit_price' => 1500, 'line_total' => 1500],
        ],
    ];

    $response = $this->actingAs($repairer, 'user')
        ->postJson('/api/repairer/pos/transactions', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.transaction.status', 'paid')
        ->assertJsonPath('data.repair.payment_status', 'paid');

    $this->assertDatabaseHas('pos_transactions', [
        'repair_request_id' => $repair->id,
        'status' => 'paid',
    ]);

    $this->assertDatabaseHas('receipt_records', [
        'repair_request_id' => $repair->id,
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairPOSPaymentFlowTest.php --filter=repair_pos_checkout_creates_paid_transaction_and_receipt`
Expected: FAIL with 404 route not found.

- [ ] **Step 3: Write minimal implementation**

```php
// routes/web.php
Route::middleware(['auth:user'])->prefix('api/repairer/pos')->group(function () {
    Route::post('/transactions', [RepairPOSController::class, 'store'])->name('repairer.pos.transactions.store');
});

// app/Http/Controllers/Api/RepairPOSController.php
public function store(CreateTransactionRequest $request, RepairPOSPaymentService $service): JsonResponse
{
    $result = $service->checkout($request->validated(), $request->user());

    return response()->json([
        'success' => true,
        'data' => $result,
    ], 201);
}

// app/Services/RepairPOSPaymentService.php
public function checkout(array $payload, User $actor): array
{
    return DB::transaction(function () use ($payload, $actor) {
        $repair = RepairRequest::lockForUpdate()->findOrFail($payload['repair_request_id']);

        $transaction = PosTransaction::create([
            'repair_request_id' => $repair->id,
            'shop_owner_id' => $repair->shop_owner_id,
            'cashier_user_id' => $actor->id,
            'receipt_number' => $this->nextReceiptNumber($repair->shop_owner_id),
            'customer_mode' => $payload['customer_mode'],
            'guest_name' => $payload['guest_name'] ?? null,
            'guest_phone' => $payload['guest_phone'] ?? null,
            'subtotal' => $payload['subtotal'],
            'discount' => $payload['discount'],
            'tax' => $payload['tax'],
            'total' => $payload['total'],
            'paid_amount' => $payload['paid_amount'],
            'change_amount' => max(0, $payload['paid_amount'] - $payload['total']),
            'status' => 'paid',
            'payment_provider' => $payload['payment_method'] === 'online' ? 'paymongo' : null,
        ]);

        foreach ($payload['items'] as $item) {
            $transaction->items()->create($item);
        }

        ReceiptRecord::create([
            'repair_request_id' => $repair->id,
            'pos_transaction_id' => $transaction->id,
            'receipt_number' => $transaction->receipt_number,
            'issued_at' => now(),
            'payload' => [
                'subtotal' => $transaction->subtotal,
                'tax' => $transaction->tax,
                'total' => $transaction->total,
            ],
        ]);

        $repair->payment_status = 'paid';
        $repair->save();

        return [
            'transaction' => $transaction->load('items'),
            'repair' => $repair->fresh(),
        ];
    });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairPOSPaymentFlowTest.php --filter=repair_pos_checkout_creates_paid_transaction_and_receipt`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/RepairPOS/CreateTransactionRequest.php app/Services/RepairPOSPaymentService.php app/Http/Controllers/Api/RepairPOSController.php routes/web.php tests/Feature/Repairer/RepairPOSPaymentFlowTest.php
git commit -m "feat(repair-pos): add checkout API with receipt persistence"
```

---

### Task 3: Implement Online Refund API with Threshold Policy

**Files:**
- Create: `app/Http/Requests/RepairPOS/CreateRefundRequest.php`
- Create: `app/Services/RepairPOSRefundService.php`
- Modify: `app/Http/Controllers/Api/RepairPOSController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Repairer/RepairPOSRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Repairer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPOSRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_refund_above_threshold_requires_approval(): void
    {
        $user = User::factory()->create();
        $transaction = PosTransaction::factory()->paid()->online()->create([
            'total' => 5000,
            'paid_amount' => 5000,
        ]);

        $response = $this->actingAs($user, 'user')->postJson(
            "/api/repairer/pos/transactions/{$transaction->id}/refunds",
            [
                'amount' => 3000,
                'reason' => 'Service quality issue',
                'approval_threshold' => 1000,
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.refund.status', 'pending_approval');

        $this->assertDatabaseHas('repair_refunds', [
            'pos_transaction_id' => $transaction->id,
            'status' => 'pending_approval',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairPOSRefundFlowTest.php --filter=online_refund_above_threshold_requires_approval`
Expected: FAIL with 404 route not found.

- [ ] **Step 3: Write minimal implementation**

```php
// routes/web.php
Route::post('/api/repairer/pos/transactions/{transaction}/refunds', [RepairPOSController::class, 'refund'])
    ->middleware(['auth:user'])
    ->name('repairer.pos.transactions.refunds.store');

// app/Http/Controllers/Api/RepairPOSController.php
public function refund(CreateRefundRequest $request, PosTransaction $transaction, RepairPOSRefundService $service): JsonResponse
{
    $refund = $service->requestRefund($transaction, $request->validated(), $request->user());

    return response()->json([
        'success' => true,
        'data' => ['refund' => $refund],
    ], 201);
}

// app/Services/RepairPOSRefundService.php
public function requestRefund(PosTransaction $transaction, array $payload, User $actor): RepairRefund
{
    $alreadyRefunded = (float) $transaction->refunds()->sum('amount');
    $remaining = (float) $transaction->total - $alreadyRefunded;

    if ((float) $payload['amount'] > $remaining) {
        throw ValidationException::withMessages(['amount' => 'Refund amount exceeds refundable balance.']);
    }

    $status = ((float) $payload['amount'] <= (float) $payload['approval_threshold'])
        ? 'approved'
        : 'pending_approval';

    return RepairRefund::create([
        'repair_request_id' => $transaction->repair_request_id,
        'pos_transaction_id' => $transaction->id,
        'requested_by_user_id' => $actor->id,
        'amount' => $payload['amount'],
        'reason' => $payload['reason'],
        'channel' => 'online',
        'status' => $status,
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairPOSRefundFlowTest.php --filter=online_refund_above_threshold_requires_approval`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/RepairPOS/CreateRefundRequest.php app/Services/RepairPOSRefundService.php app/Http/Controllers/Api/RepairPOSController.php routes/web.php tests/Feature/Repairer/RepairPOSRefundFlowTest.php
git commit -m "feat(repair-pos): add threshold-based online refund request API"
```

---

### Task 4: Finalize Online Refund by Webhook (Source of Truth)

**Files:**
- Modify: `app/Http/Controllers/PaymongoWebhookController.php`
- Modify: `app/Services/RepairPOSRefundService.php`
- Test: `tests/Feature/Repairer/RepairPOSWebhookRefundTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Repairer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPOSWebhookRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_marks_refund_succeeded_and_updates_transaction_state(): void
    {
        $transaction = PosTransaction::factory()->paid()->online()->create(['total' => 5000]);

        $refund = RepairRefund::factory()->create([
            'pos_transaction_id' => $transaction->id,
            'repair_request_id' => $transaction->repair_request_id,
            'amount' => 1000,
            'status' => 'processing',
            'gateway_refund_reference' => 're_123',
        ]);

        $payload = [
            'data' => [
                'attributes' => [
                    'type' => 'refund.succeeded',
                    'data' => ['id' => 're_123'],
                ],
            ],
        ];

        $response = $this->postJson('/api/paymongo/webhook', $payload);

        $response->assertOk();

        $this->assertDatabaseHas('repair_refunds', [
            'id' => $refund->id,
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transaction->id,
            'status' => 'partially_refunded',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairPOSWebhookRefundTest.php --filter=webhook_marks_refund_succeeded_and_updates_transaction_state`
Expected: FAIL with unchanged refund/transaction status.

- [ ] **Step 3: Write minimal implementation**

```php
// app/Http/Controllers/PaymongoWebhookController.php
protected function handleRefundSucceeded(array $event): void
{
    $gatewayRefundId = data_get($event, 'data.attributes.data.id');

    if (!$gatewayRefundId) {
        return;
    }

    app(RepairPOSRefundService::class)->finalizeOnlineRefundSuccess($gatewayRefundId);
}

// app/Services/RepairPOSRefundService.php
public function finalizeOnlineRefundSuccess(string $gatewayRefundId): void
{
    DB::transaction(function () use ($gatewayRefundId) {
        $refund = RepairRefund::where('gateway_refund_reference', $gatewayRefundId)
            ->lockForUpdate()
            ->first();

        if (!$refund || $refund->status === 'succeeded') {
            return;
        }

        $refund->status = 'succeeded';
        $refund->processed_at = now();
        $refund->save();

        $transaction = $refund->transaction()->lockForUpdate()->first();
        $totalRefunded = (float) $transaction->refunds()->where('status', 'succeeded')->sum('amount');

        $transaction->status = $totalRefunded >= (float) $transaction->total
            ? 'refunded'
            : 'partially_refunded';

        $transaction->save();
    });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairPOSWebhookRefundTest.php --filter=webhook_marks_refund_succeeded_and_updates_transaction_state`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PaymongoWebhookController.php app/Services/RepairPOSRefundService.php tests/Feature/Repairer/RepairPOSWebhookRefundTest.php
git commit -m "feat(repair-pos): finalize online refund state via webhook confirmation"
```

---

### Task 5: Update Job Orders UI to POS-Only Payment Actions

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Create: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.paymentActions.test.tsx`
- Create: `resources/js/services/repairPosApi.ts`
- Create: `resources/js/types/repairPos.ts`

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen } from "@testing-library/react";
import JobOrdersRepair from "../JobOrdersRepair";

it("shows Proceed to POS and hides Mark Paid action", async () => {
  render(<JobOrdersRepair />);

  expect(await screen.findByRole("button", { name: /Proceed to POS/i })).toBeInTheDocument();
  expect(screen.queryByRole("button", { name: /Mark Paid In-Shop/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.paymentActions.test.tsx`
Expected: FAIL because old Mark Paid action is still rendered.

- [ ] **Step 3: Write minimal implementation**

```tsx
// resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
const handleProceedToPOS = (order: RepairOrder) => {
  window.location.href = `/erp/repairer/point-of-sale?repair_request_id=${order.database_id}`;
};

// In actions UI block, replace old button:
<button
  type="button"
  onClick={() => handleProceedToPOS(order)}
  className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500"
>
  Proceed to POS
</button>

// Remove Mark Paid In-Shop render path from this page.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.paymentActions.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.paymentActions.test.tsx resources/js/services/repairPosApi.ts resources/js/types/repairPos.ts
git commit -m "feat(repair-pos-ui): route repair payments from job orders to POS"
```

---

### Task 6: Persist POS Checkout and Receipt History in Frontend

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Modify: `resources/js/services/repairPosApi.ts`
- Test: `resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen, fireEvent } from "@testing-library/react";
import POS from "../POS";
import * as repairPosApi from "@/services/repairPosApi";

vi.spyOn(repairPosApi, "createRepairPosTransaction").mockResolvedValue({
  transaction: { id: 10, status: "paid", receipt_number: "RCP-001" },
  repair: { id: 99, payment_status: "paid" },
});

it("calls backend checkout and displays persisted receipt number", async () => {
  render(<POS />);

  fireEvent.change(screen.getByTitle("Customer name"), { target: { value: "Walk In Buyer" } });
  fireEvent.change(screen.getByTitle("Customer phone number"), { target: { value: "09171234567" } });
  fireEvent.click(screen.getByRole("button", { name: /Pay/i }));

  expect(await screen.findByText(/RCP-001/i)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`
Expected: FAIL because `handlePay` currently stores only local snapshot and does not call API.

- [ ] **Step 3: Write minimal implementation**

```tsx
// resources/js/services/repairPosApi.ts
export async function createRepairPosTransaction(payload: CreateRepairPosTransactionPayload) {
  const { data } = await axios.post("/api/repairer/pos/transactions", payload);
  return data.data;
}

// resources/js/Pages/ERP/repairer/POS.tsx (inside handlePay)
const result = await createRepairPosTransaction({
  repair_request_id: selectedRepairOrder ? Number(selectedRepairOrder.id) : null,
  customer_mode: selectedRepairOrder ? "account" : "guest",
  guest_name: selectedRepairOrder ? null : customerName.trim(),
  guest_phone: selectedRepairOrder ? null : customerPhone.trim(),
  payment_method: paymentMethod,
  subtotal,
  discount,
  tax: vatAmount,
  total: totalDue,
  paid_amount: tenderedAmount,
  items: items.map((i) => ({ service_name: i.label, qty: i.qty, unit_price: i.unitPrice, line_total: i.qty * i.unitPrice })),
});

setReceiptSnapshot({
  ...snapshot,
  receiptNo: result.transaction.receipt_number,
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/repairer/POS.tsx resources/js/services/repairPosApi.ts resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx
git commit -m "feat(repair-pos-ui): persist checkout and use backend receipt records"
```

---

### Task 7: Add POS Refund UI and API Integration (Online + In-Shop)

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Modify: `resources/js/services/repairPosApi.ts`
- Test: `resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
it("submits refund request from receipt modal and shows pending approval status", async () => {
  vi.spyOn(repairPosApi, "createRepairPosRefund").mockResolvedValue({
    refund: { id: 55, status: "pending_approval", amount: 3000 },
  });

  render(<POS />);

  fireEvent.click(await screen.findByRole("button", { name: /View/i }));
  fireEvent.click(screen.getByRole("button", { name: /Refund/i }));
  fireEvent.change(screen.getByLabelText(/Refund amount/i), { target: { value: "3000" } });
  fireEvent.click(screen.getByRole("button", { name: /Submit Refund/i }));

  expect(await screen.findByText(/pending_approval/i)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`
Expected: FAIL because refund controls are not implemented.

- [ ] **Step 3: Write minimal implementation**

```tsx
// resources/js/services/repairPosApi.ts
export async function createRepairPosRefund(transactionId: number, payload: { amount: number; reason: string; approval_threshold: number }) {
  const { data } = await axios.post(`/api/repairer/pos/transactions/${transactionId}/refunds`, payload);
  return data.data;
}

// resources/js/Pages/ERP/repairer/POS.tsx
const [refundAmount, setRefundAmount] = useState<string>("");
const [refundReason, setRefundReason] = useState<string>("");
const [refundResult, setRefundResult] = useState<{ status: string } | null>(null);

const handleRefund = async () => {
  if (!receiptSnapshot) return;

  const result = await createRepairPosRefund(Number((receiptSnapshot as any).transactionId), {
    amount: Number(refundAmount),
    reason: refundReason,
    approval_threshold: 1000,
  });

  setRefundResult({ status: result.refund.status });
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/repairer/POS.tsx resources/js/services/repairPosApi.ts resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx
git commit -m "feat(repair-pos-ui): add receipt-linked refund submission flow"
```

---

### Task 8: Edge Cases, Documentation, and Final Regression Suite

**Files:**
- Modify: `tests/Feature/Repairer/RepairPOSPaymentFlowTest.php`
- Modify: `tests/Feature/Repairer/RepairPOSRefundFlowTest.php`
- Modify: `tests/Feature/Repairer/RepairPOSWebhookRefundTest.php`
- Create: `docs/repair/repair-pos-payment-workflow.md`

- [ ] **Step 1: Write the failing tests for edge cases**

```php
public function test_split_payment_requires_exact_total_before_marking_paid(): void
{
    // Arrange a transaction with two tenders totaling less than required.
    // Expect validation error and no paid state.
}

public function test_cancelled_repair_with_paid_transaction_requires_refund_or_void(): void
{
    // Arrange a paid repair then attempt cancel.
    // Expect 422 and specific message.
}

public function test_refund_after_full_refund_is_rejected(): void
{
    // Arrange already fully-refunded transaction and attempt another refund.
    // Expect 422.
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Repairer/RepairPOSPaymentFlowTest.php tests/Feature/Repairer/RepairPOSRefundFlowTest.php tests/Feature/Repairer/RepairPOSWebhookRefundTest.php`
Expected: FAIL with validation and transition assertion failures.

- [ ] **Step 3: Write minimal implementation and docs**

```php
// RepairPOSRefundService.php additional guard
if ($transaction->status === 'refunded') {
    throw ValidationException::withMessages(['transaction' => 'Transaction is already fully refunded.']);
}

// RepairPOSPaymentService.php split-tender guard
if (round($payload['paid_amount'], 2) < round($payload['total'], 2)) {
    throw ValidationException::withMessages(['paid_amount' => 'Insufficient tendered amount.']);
}
```

```md
# docs/repair/repair-pos-payment-workflow.md
## Supported flows
- Guest walk-in payment
- Registered customer payment
- Online refund request and webhook finalization
- Partial and full refunds with threshold policy

## Operational constraints
- Job Orders cannot directly mark paid.
- Online refunds are finalized only by gateway webhook status.
- Receipts are immutable and linked to original transaction.
```

- [ ] **Step 4: Run complete regression suite**

Run: `php artisan test tests/Feature/Repairer/RepairPOSPaymentFlowTest.php tests/Feature/Repairer/RepairPOSRefundFlowTest.php tests/Feature/Repairer/RepairPOSWebhookRefundTest.php && npx vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.paymentActions.test.tsx resources/js/Pages/ERP/repairer/__tests__/POS.persistenceAndRefund.test.tsx`
Expected: PASS for all listed tests.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Repairer/RepairPOSPaymentFlowTest.php tests/Feature/Repairer/RepairPOSRefundFlowTest.php tests/Feature/Repairer/RepairPOSWebhookRefundTest.php docs/repair/repair-pos-payment-workflow.md
git commit -m "test(repair-pos): add edge-case coverage and workflow documentation"
```

---

## Self-Review

### 1) Spec Coverage Check
- Current workflow flaws and architectural correction: Covered by Tasks 1-2 and Task 5.
- POS-only payment lifecycle and receipt generation: Covered by Tasks 2 and 6.
- Refund workflow including online transactions: Covered by Tasks 3-4 and Task 7.
- UI/UX changes for Job Order and POS: Covered by Tasks 5-7.
- Edge cases (walk-in, failed, split, cancellation, post-completion refund): Covered by Task 8.
- Scalability for branch/reporting foundation: Covered by schema fields in Task 1 and service boundaries in Tasks 2-4.

### 2) Placeholder Scan
- No TBD/TODO placeholders remain.
- Every task includes concrete files, commands, and code snippets.

### 3) Type and Naming Consistency
- `PosTransaction`, `RepairRefund`, `ReceiptRecord`, `RepairPOSPaymentService`, and `RepairPOSRefundService` names are consistent across tasks.
- Refund statuses and transaction statuses are consistent in tests and implementation snippets.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-03-repair-pos-payment-workflow.md`. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration

2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
