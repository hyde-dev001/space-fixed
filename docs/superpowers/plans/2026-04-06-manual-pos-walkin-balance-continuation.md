# Manual POS Walk-in Balance Continuation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a POS-native Manual Walk-in Queue so staff can move manual walk-in records through status flow and collect remaining 50% balance at `ready_for_pickup`.

**Architecture:** Extend `repair_requests` with a server-controlled queue marker, expose dedicated `repair-pos` queue/read/update endpoints, and integrate a new queue panel in Shop Owner POS. Keep REP-POS records excluded from generic job-order pages while preserving existing checkout/refund flows.

**Tech Stack:** Laravel, MySQL migrations, PHPUnit feature tests, React + TypeScript (Inertia), Axios

---

## File Structure Map

### Data model and persistence
- Create: `database/migrations/2026_04_06_120000_add_manual_pos_queue_enabled_to_repair_requests_table.php`
- Modify: `app/Models/RepairRequest.php`

### API behavior
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `routes/api.php`

### POS UI integration
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`

### Tests
- Create: `tests/Feature/RepairPosManualQueueTest.php`

---

### Task 1: Add queue marker field to repair requests

**Files:**
- Create: `database/migrations/2026_04_06_120000_add_manual_pos_queue_enabled_to_repair_requests_table.php`
- Modify: `app/Models/RepairRequest.php`
- Test: `tests/Feature/RepairPosManualQueueTest.php`

- [ ] **Step 1: Write failing test for default queue marker behavior**

```php
<?php

namespace Tests\Feature;

use App\Models\RepairRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPosManualQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_request_defaults_manual_pos_queue_to_false(): void
    {
        $repair = RepairRequest::factory()->create();

        $this->assertFalse((bool) $repair->manual_pos_queue_enabled);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php --filter=defaults_manual_pos_queue`
Expected: FAIL with unknown column `manual_pos_queue_enabled`.

- [ ] **Step 3: Add migration and model support**

```php
// database/migrations/2026_04_06_120000_add_manual_pos_queue_enabled_to_repair_requests_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_requests', 'manual_pos_queue_enabled')) {
                $table->boolean('manual_pos_queue_enabled')
                    ->default(false)
                    ->after('latest_pos_transaction_id');
                $table->index(['shop_owner_id', 'manual_pos_queue_enabled'], 'repair_requests_manual_pos_queue_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            if (Schema::hasColumn('repair_requests', 'manual_pos_queue_enabled')) {
                $table->dropIndex('repair_requests_manual_pos_queue_idx');
                $table->dropColumn('manual_pos_queue_enabled');
            }
        });
    }
};
```

```php
// app/Models/RepairRequest.php (additions)
protected $fillable = [
    // ...existing fields
    'manual_pos_queue_enabled',
];

protected $casts = [
    // ...existing casts
    'manual_pos_queue_enabled' => 'boolean',
];
```

- [ ] **Step 4: Run migration and test**

Run: `php artisan migrate`
Expected: migration success.

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php --filter=defaults_manual_pos_queue`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_06_120000_add_manual_pos_queue_enabled_to_repair_requests_table.php app/Models/RepairRequest.php tests/Feature/RepairPosManualQueueTest.php
git commit -m "feat: add manual POS queue marker for repair requests"
```

---

### Task 2: Mark new manual POS-created records as queue-enabled

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Test: `tests/Feature/RepairPosManualQueueTest.php`

- [ ] **Step 1: Write failing test for manual checkout marking queue-enabled**

```php
public function test_manual_pos_checkout_marks_repair_request_as_queue_enabled(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $user = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $response = $this->actingAs($user, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => null,
        'due_type' => 'deposit',
        'idempotency_key' => 'manual-pos-queue-001',
        'customer_type' => 'walk_in',
        'walk_in_name' => 'Queue Test',
        'walk_in_phone' => '09170000001',
        'manual_repair_subtotal' => 500,
        'manual_service_summary' => 'Manual queue creation test',
        'manual_payment_policy' => 'deposit_50',
        'payment_lines' => [
            ['tender_type' => 'cash', 'amount' => 250],
        ],
    ]);

    $response->assertOk();

    $tx = \App\Models\PosTransaction::findOrFail((int) $response->json('transaction_id'));
    $repair = \App\Models\RepairRequest::findOrFail((int) $tx->module_reference_id);

    $this->assertTrue($repair->manual_pos_queue_enabled);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php --filter=marks_repair_request_as_queue_enabled`
Expected: FAIL (`manual_pos_queue_enabled` is false).

- [ ] **Step 3: Set marker in manual create path**

```php
// app/Http/Controllers/Api/RepairPosController.php inside createManualRepairRequestFromPos create([...])
'manual_pos_queue_enabled' => true,
```

- [ ] **Step 4: Re-run test**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php --filter=marks_repair_request_as_queue_enabled`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPosController.php tests/Feature/RepairPosManualQueueTest.php
git commit -m "feat: flag manual POS-created repairs for queue workflow"
```

---

### Task 3: Add manual queue list and status transition endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/RepairPosManualQueueTest.php`

- [ ] **Step 1: Write failing tests for queue list and valid transition**

```php
public function test_manual_queue_list_returns_only_queue_enabled_records(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $user = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $included = \App\Models\RepairRequest::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'request_id' => 'REP-POS-20260406-0001',
        'manual_pos_queue_enabled' => true,
        'status' => 'pending',
        'payment_policy' => 'deposit_50',
        'payment_status' => 'paid',
        'final_total' => 500,
        'total_paid_amount' => 250,
    ]);

    \App\Models\RepairRequest::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'request_id' => 'REP-POS-20260406-0002',
        'manual_pos_queue_enabled' => false,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($user, 'user')->getJson('/api/repair-pos/manual-queue');

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $this->assertCount(1, $response->json('data'));
    $this->assertSame($included->id, (int) $response->json('data.0.id'));
}

public function test_manual_queue_status_transition_is_restricted(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $user = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $repair = \App\Models\RepairRequest::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'request_id' => 'REP-POS-20260406-0003',
        'manual_pos_queue_enabled' => true,
        'status' => 'pending',
    ]);

    $invalid = $this->actingAs($user, 'user')->patchJson("/api/repair-pos/manual-queue/{$repair->id}/status", [
        'status' => 'in_progress',
    ]);

    $invalid->assertStatus(422);

    $valid = $this->actingAs($user, 'user')->patchJson("/api/repair-pos/manual-queue/{$repair->id}/status", [
        'status' => 'received',
    ]);

    $valid->assertOk();
    $this->assertSame('received', $repair->fresh()->status);
}
```

- [ ] **Step 2: Run test to verify failures**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php --filter=manual_queue`
Expected: FAIL with missing route/controller methods.

- [ ] **Step 3: Implement queue list and transition methods**

```php
// app/Http/Controllers/Api/RepairPosController.php
public function listManualQueue(Request $request)
{
    $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
    if ($shopOwnerId <= 0) {
        return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }

    $q = trim((string) $request->query('q', ''));

    $rows = RepairRequest::query()
        ->where('shop_owner_id', $shopOwnerId)
        ->where('manual_pos_queue_enabled', true)
        ->where('request_id', 'like', 'REP-POS-%')
        ->whereIn('status', ['pending', 'received', 'in_progress', 'ready_for_pickup'])
        ->when($q !== '', function ($query) use ($q) {
            $query->where(function ($inner) use ($q) {
                $inner->where('request_id', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhereHas('latestPosTransaction.receipt', fn ($receiptQ) => $receiptQ->where('receipt_no', 'like', "%{$q}%"));
            });
        })
        ->orderByDesc('id')
        ->get();

    $data = $rows->map(function (RepairRequest $repair) {
        $total = (float) ($repair->final_total ?? $repair->total ?? 0);
        $paid = (float) ($repair->total_paid_amount ?? 0);
        $remaining = max(0, round($total - $paid, 2));

        $nextDueType = null;
        if (($repair->payment_policy ?? 'deposit_50') === 'deposit_50') {
            if ((string) $repair->payment_status === 'unpaid' || $paid <= 0) {
                $nextDueType = 'deposit';
            } elseif ((string) $repair->status === 'ready_for_pickup' && $remaining > 0) {
                $nextDueType = 'balance';
            }
        } elseif ($remaining > 0) {
            $nextDueType = 'full';
        }

        return [
            'id' => $repair->id,
            'request_id' => $repair->request_id,
            'customer_name' => $repair->customer_name,
            'phone' => $repair->phone,
            'status' => $repair->status,
            'payment_policy' => $repair->payment_policy,
            'total' => $total,
            'paid' => $paid,
            'remaining_balance' => $remaining,
            'next_due_type' => $nextDueType,
            'receipt_no' => $repair->latestPosTransaction?->receipt?->receipt_no,
        ];
    })->values();

    return response()->json(['success' => true, 'data' => $data]);
}

public function updateManualQueueStatus(Request $request, int $repairId)
{
    $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
    if ($shopOwnerId <= 0) {
        return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }

    $validated = $request->validate([
        'status' => ['required', 'string', 'in:received,in_progress,ready_for_pickup,picked_up'],
    ]);

    $repair = RepairRequest::query()
        ->where('id', $repairId)
        ->where('shop_owner_id', $shopOwnerId)
        ->where('manual_pos_queue_enabled', true)
        ->firstOrFail();

    $allowed = [
        'pending' => 'received',
        'received' => 'in_progress',
        'in_progress' => 'ready_for_pickup',
        'ready_for_pickup' => 'picked_up',
    ];

    $current = (string) $repair->status;
    $target = (string) $validated['status'];

    if (!isset($allowed[$current]) || $allowed[$current] !== $target) {
        return response()->json([
            'success' => false,
            'message' => "Invalid transition from {$current} to {$target}.",
        ], 422);
    }

    $updates = ['status' => $target];
    if ($target === 'received') $updates['received_at'] = now();
    if ($target === 'in_progress') $updates['started_at'] = now();
    if ($target === 'ready_for_pickup') $updates['completed_at'] = now();
    if ($target === 'picked_up') $updates['picked_up_at'] = now();

    $repair->update($updates);

    return response()->json(['success' => true, 'data' => $repair->fresh()]);
}
```

```php
// routes/api.php inside repair-pos group
Route::get('/manual-queue', [\App\Http\Controllers\Api\RepairPosController::class, 'listManualQueue']);
Route::patch('/manual-queue/{repairId}/status', [\App\Http\Controllers\Api\RepairPosController::class, 'updateManualQueueStatus']);
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPosController.php routes/api.php tests/Feature/RepairPosManualQueueTest.php
git commit -m "feat: add manual POS queue list and status transition APIs"
```

---

### Task 4: Integrate Manual Walk-in Queue in Shop Owner POS UI

**Files:**
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
- Test: `tests/Feature/RepairPosManualQueueTest.php` (API contract confidence)

- [ ] **Step 1: Add queue state and fetch function**

```tsx
// POS.tsx additions

type ManualQueueRow = {
  id: number;
  request_id: string;
  customer_name: string;
  phone: string;
  status: 'pending' | 'received' | 'in_progress' | 'ready_for_pickup' | 'picked_up';
  payment_policy: 'deposit_50' | 'full_upfront';
  total: number;
  paid: number;
  remaining_balance: number;
  next_due_type: 'deposit' | 'balance' | 'full' | null;
  receipt_no?: string | null;
};

const [manualQueue, setManualQueue] = useState<ManualQueueRow[]>([]);
const [manualQueueSearch, setManualQueueSearch] = useState('');
const [manualQueueLoading, setManualQueueLoading] = useState(false);
const [manualQueueActionLoadingId, setManualQueueActionLoadingId] = useState<number | null>(null);

const fetchManualQueue = async (search = '') => {
  setManualQueueLoading(true);
  try {
    const response = await axios.get('/api/repair-pos/manual-queue', {
      params: search.trim() ? { q: search.trim() } : {},
      withCredentials: true,
    });
    setManualQueue(Array.isArray(response?.data?.data) ? response.data.data : []);
  } catch {
    setManualQueue([]);
  } finally {
    setManualQueueLoading(false);
  }
};

useEffect(() => {
  fetchManualQueue();
}, []);
```

- [ ] **Step 2: Add helper for next status transition and continue-payment action**

```tsx
const nextStatusMap: Record<string, ManualQueueRow['status'] | null> = {
  pending: 'received',
  received: 'in_progress',
  in_progress: 'ready_for_pickup',
  ready_for_pickup: 'picked_up',
  picked_up: null,
};

const advanceManualQueueStatus = async (row: ManualQueueRow) => {
  const nextStatus = nextStatusMap[row.status];
  if (!nextStatus) return;

  setManualQueueActionLoadingId(row.id);
  try {
    await axios.patch(`/api/repair-pos/manual-queue/${row.id}/status`, { status: nextStatus }, { withCredentials: true });
    await fetchManualQueue(manualQueueSearch);
    await Swal.fire({ icon: 'success', title: 'Status updated', timer: 1200, showConfirmButton: false });
  } catch (error: any) {
    await Swal.fire({ icon: 'error', title: 'Status update failed', text: error?.response?.data?.message || 'Please try again.' });
  } finally {
    setManualQueueActionLoadingId(null);
  }
};

const continueManualQueuePayment = (row: ManualQueueRow) => {
  if (!row.next_due_type) return;

  setSelectedRepairOrder({
    id: String(row.id),
    customer: row.customer_name,
    customerId: null,
    paymentPolicy: row.payment_policy,
    paymentStatus: row.remaining_balance <= 0 ? 'completed' : 'paid',
    status: row.status,
    returnDeliveryMethod: 'walk_in',
    dueTypeToCollect: row.next_due_type,
    service: row.request_id,
    amount: Number(row.total),
    requestedServices: [row.request_id],
  });

  setCustomerName(row.customer_name || 'Walk-in Customer');
  setCustomerPhone(row.phone || '');

  const dueAmount = row.next_due_type === 'deposit'
    ? Math.round((Number(row.total) * 0.5) * 100) / 100
    : Number(row.next_due_type === 'balance' ? row.remaining_balance : row.total);

  setItems([
    {
      id: `manual-queue-${row.id}-${row.next_due_type}`,
      label: `${row.request_id} (${row.next_due_type})`,
      qty: 1,
      unitPrice: dueAmount,
      source: 'repair-order',
    },
  ]);
};
```

- [ ] **Step 3: Render Manual Walk-in Queue panel in POS page**

```tsx
<section className="rounded-xl border border-slate-200 bg-white p-4">
  <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
    <h3 className="text-sm font-semibold text-slate-800">Manual Walk-in Queue</h3>
    <div className="flex gap-2">
      <input
        value={manualQueueSearch}
        onChange={(e) => setManualQueueSearch(e.target.value)}
        placeholder="Search receipt / name / phone"
        className="rounded border px-3 py-1.5 text-sm"
      />
      <button
        onClick={() => fetchManualQueue(manualQueueSearch)}
        className="rounded bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white"
      >
        Search
      </button>
    </div>
  </div>

  {manualQueueLoading ? (
    <p className="text-sm text-slate-500">Loading manual queue...</p>
  ) : manualQueue.length === 0 ? (
    <p className="text-sm text-slate-500">No manual walk-in records ready for queue.</p>
  ) : (
    <div className="space-y-2">
      {manualQueue.map((row) => (
        <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 rounded border p-3">
          <div>
            <p className="text-sm font-semibold text-slate-800">{row.request_id}</p>
            <p className="text-xs text-slate-600">{row.customer_name} • {row.phone || 'No phone'}</p>
            <p className="text-xs text-slate-600">Status: {row.status}</p>
            <p className="text-xs text-slate-600">Remaining: {formatPeso(row.remaining_balance)}</p>
          </div>
          <div className="flex gap-2">
            <button
              onClick={() => advanceManualQueueStatus(row)}
              disabled={manualQueueActionLoadingId === row.id || !nextStatusMap[row.status]}
              className="rounded bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
            >
              Next Status
            </button>
            <button
              onClick={() => continueManualQueuePayment(row)}
              disabled={!row.next_due_type}
              className="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
            >
              Continue Payment
            </button>
          </div>
        </div>
      ))}
    </div>
  )}
</section>
```

- [ ] **Step 4: Run frontend and targeted backend tests**

Run: `php artisan test tests/Feature/RepairPosManualQueueTest.php`
Expected: PASS.

Run: `php artisan test --filter=RepairPosPaymentFlowTest`
Expected: PASS (no checkout regression).

- [ ] **Step 5: Commit**

```bash
git add "resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx" tests/Feature/RepairPosManualQueueTest.php
git commit -m "feat: add manual walk-in queue and continue payment actions to POS"
```

---

### Task 5: Regression verification and release checklist

**Files:**
- Modify: `docs/plans/2026-04-06-manual-pos-walkin-balance-design.md` (append verification log)

- [ ] **Step 1: Execute regression suite**

Run: `php artisan test --filter=RepairPosPaymentFlowTest`
Expected: PASS.

Run: `php artisan test --filter=RepairMaterialCompletionVarianceTest`
Expected: PASS.

- [ ] **Step 2: Manual verification flow**

Run these checks in UI:
1. Create manual walk-in with `deposit_50` policy.
2. Confirm record appears in Manual Walk-in Queue.
3. Advance status to `ready_for_pickup`.
4. Click Continue Payment and collect balance.
5. Advance to `picked_up`.
6. Verify record is no longer in active queue.

Expected: all steps successful, no job-order page contamination.

- [ ] **Step 3: Commit verification note**

```bash
git add docs/plans/2026-04-06-manual-pos-walkin-balance-design.md
git commit -m "docs: add manual POS walk-in queue verification checklist"
```

---

## Self-Review Checklist

- [ ] Spec coverage validated against approved design sections 1-4.
- [ ] No placeholder phrases (TBD/TODO/implement later) remain.
- [ ] API and status names are consistent across controller, routes, UI, and tests.
- [ ] Balance collection gate is enforced only at `ready_for_pickup`.
- [ ] Legacy records remain excluded by `manual_pos_queue_enabled = false`.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-06-manual-pos-walkin-balance-continuation.md`. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration
2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?