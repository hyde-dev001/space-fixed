# ERP POS Unified Repair-Retail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Deliver a single ERP POS page that supports repair and retail walk-in sales with strict business-type-aware UI and API access rules.

**Architecture:** Keep one POS UI entry point and split behavior by allowed mode derived from business type. Repair mode continues using existing repair POS backend unchanged, while retail mode uses new retail POS endpoints that persist to existing orders and order_items plus existing stock deduction patterns.

**Tech Stack:** Laravel 11, Inertia React TypeScript, Axios, PHPUnit feature tests, Vitest React tests.

---

### Task 1: Add POS Mode Gating in ERP POS UI

**Files:**
- Modify: resources/js/Pages/ERP/repairer/POS.tsx
- Create: resources/js/services/retailPosApi.ts
- Test: resources/js/Pages/ERP/repairer/__tests__/POS.mode-gating.test.tsx

- [ ] **Step 1: Write the failing frontend test for mode visibility matrix**

```tsx
import { render, screen } from "@testing-library/react";
import PointOfSalePage from "../POS";

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({
    props: {
      auth: {
        user: {
          name: "Cashier",
          shop_owner: { business_type: "retail" },
        },
      },
    },
  }),
  Head: () => null,
}));

describe("POS mode gating", () => {
  it("shows only retail UI for retail business type", () => {
    render(<PointOfSalePage />);
    expect(screen.getByText("Point of Sale")).toBeInTheDocument();
    expect(screen.getByText("Retail Catalog")).toBeInTheDocument();
    expect(screen.queryByText("Attach From Repair Orders")).not.toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: /repair/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: pnpm vitest resources/js/Pages/ERP/repairer/__tests__/POS.mode-gating.test.tsx -t "shows only retail UI"
Expected: FAIL with missing retail section or unexpected repair section rendering.

- [ ] **Step 3: Implement mode normalization and conditional rendering in POS.tsx**

```tsx
type PosMode = "repair" | "retail";

const normalizeBusinessType = (raw: unknown): "retail" | "repair" | "both" => {
  const value = String(raw || "").toLowerCase();
  if (value.includes("both")) return "both";
  if (value.includes("retail")) return "retail";
  return "repair";
};

const buildAllowedModes = (businessType: "retail" | "repair" | "both"): PosMode[] => {
  if (businessType === "both") return ["repair", "retail"];
  return [businessType];
};

const PointOfSalePage = () => {
  const { props } = usePage();
  const businessType = normalizeBusinessType((props as any)?.auth?.user?.shop_owner?.business_type);
  const allowedModes = buildAllowedModes(businessType);
  const [posMode, setPosMode] = useState<PosMode>(allowedModes[0]);

  useEffect(() => {
    if (!allowedModes.includes(posMode)) {
      setPosMode(allowedModes[0]);
    }
  }, [allowedModes, posMode]);

  const showRepairMode = posMode === "repair" && allowedModes.includes("repair");
  const showRetailMode = posMode === "retail" && allowedModes.includes("retail");

  return (
    <>
      {allowedModes.length === 2 && (
        <div role="tablist" aria-label="POS Mode">
          <button role="tab" aria-selected={posMode === "repair"} onClick={() => setPosMode("repair")}>Repair</button>
          <button role="tab" aria-selected={posMode === "retail"} onClick={() => setPosMode("retail")}>Retail</button>
        </div>
      )}
      {showRepairMode && <section>Attach From Repair Orders</section>}
      {showRetailMode && <section>Retail Catalog</section>}
    </>
  );
};
```

- [ ] **Step 4: Add retail API client and wire retail mode loaders**

```ts
import axios from "axios";

export const retailPosApi = {
  listProducts(search = "") {
    return axios.get("/api/retail-pos/products", {
      params: search.trim() ? { q: search.trim() } : {},
      withCredentials: true,
    });
  },
  checkout(payload: Record<string, unknown>) {
    return axios.post("/api/retail-pos/checkout", payload, { withCredentials: true });
  },
  history(limit = 200) {
    return axios.get("/api/retail-pos/history", { params: { limit }, withCredentials: true });
  },
};
```

- [ ] **Step 5: Run frontend tests to verify pass**

Run: pnpm vitest resources/js/Pages/ERP/repairer/__tests__/POS.mode-gating.test.tsx
Expected: PASS for retail-only, repair-only, and both matrix tests.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/ERP/repairer/POS.tsx resources/js/services/retailPosApi.ts resources/js/Pages/ERP/repairer/__tests__/POS.mode-gating.test.tsx
git commit -m "feat(pos): add business-type aware repair-retail mode gating"
```

### Task 2: Align Sidebar Visibility with Business Type for POS Item

**Files:**
- Modify: resources/js/layout/AppSidebar_ERP.tsx
- Test: resources/js/layout/__tests__/AppSidebar_ERP.pos-visibility.test.tsx

- [ ] **Step 1: Write failing test for POS nav visibility**

```tsx
it("hides repair POS item for retail-only business type", () => {
  const { queryByText } = renderSidebar({ businessType: "retail", permissions: ["access-repairer-dashboard"] });
  expect(queryByText("Point of Sale")).toBeNull();
});

it("shows POS item for both business type", () => {
  const { getByText } = renderSidebar({ businessType: "both", permissions: ["access-repairer-dashboard"] });
  expect(getByText("Point of Sale")).toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: pnpm vitest resources/js/layout/__tests__/AppSidebar_ERP.pos-visibility.test.tsx
Expected: FAIL because POS menu currently checks permissions without strict business-type rule.

- [ ] **Step 3: Implement POS route visibility guard in sidebar**

```tsx
if (item.route === "erp.repairer.point-of-sale") {
  const hasRepairMode = normalizedBusinessType === "repair" || normalizedBusinessType === "both";
  if (!hasRepairMode) {
    return false;
  }

  return permissions.includes("access-repair-job-orders") || permissions.includes("access-repairer-dashboard");
}
```

- [ ] **Step 4: Run sidebar test suite**

Run: pnpm vitest resources/js/layout/__tests__/AppSidebar_ERP.pos-visibility.test.tsx
Expected: PASS with visibility matrix validated.

- [ ] **Step 5: Commit**

```bash
git add resources/js/layout/AppSidebar_ERP.tsx resources/js/layout/__tests__/AppSidebar_ERP.pos-visibility.test.tsx
git commit -m "fix(sidebar): gate POS visibility by business type"
```

### Task 3: Add Retail POS API Routes and Business-Type Authorization

**Files:**
- Modify: routes/api.php
- Create: app/Http/Controllers/Api/RetailPosController.php
- Test: tests/Feature/Api/RetailPosBusinessTypeAccessTest.php

- [ ] **Step 1: Write failing feature test for endpoint access matrix**

```php
public function test_retail_only_user_cannot_access_repair_pos_endpoint(): void
{
    $user = User::factory()->create(['shop_owner_id' => $this->retailOwner->id]);
    $this->actingAs($user, 'user');

    $response = $this->postJson('/api/repair-pos/checkout', []);

    $response->assertStatus(403)
        ->assertJsonPath('code', 'BUSINESS_TYPE_FORBIDDEN_MODE');
}

public function test_retail_only_user_can_access_retail_pos_products(): void
{
    $user = User::factory()->create(['shop_owner_id' => $this->retailOwner->id]);
    $this->actingAs($user, 'user');

    $response = $this->getJson('/api/retail-pos/products');

    $response->assertOk();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: php artisan test tests/Feature/Api/RetailPosBusinessTypeAccessTest.php
Expected: FAIL because retail-pos routes and controller do not exist.

- [ ] **Step 3: Add retail-pos routes and controller skeleton**

```php
Route::middleware(['web', 'auth:user,shop_owner'])->prefix('retail-pos')->group(function () {
    Route::get('/products', [\App\Http\Controllers\Api\RetailPosController::class, 'listProducts']);
    Route::post('/checkout', [\App\Http\Controllers\Api\RetailPosController::class, 'checkout']);
    Route::get('/history', [\App\Http\Controllers\Api\RetailPosController::class, 'history']);
    Route::get('/orders/{order}/receipt', [\App\Http\Controllers\Api\RetailPosController::class, 'receipt']);
});
```

```php
class RetailPosController extends Controller
{
    public function listProducts(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        $q = trim((string) $request->query('q', ''));
        $rows = Product::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name', 'price', 'stock_quantity', 'slug']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function assertRetailOrBoth(int $shopOwnerId): void
    {
        $businessType = strtolower((string) ShopOwner::query()->whereKey($shopOwnerId)->value('business_type'));
        if (!str_contains($businessType, 'retail') && !str_contains($businessType, 'both')) {
            abort(response()->json([
                'success' => false,
                'code' => 'BUSINESS_TYPE_FORBIDDEN_MODE',
                'message' => 'Retail POS is not available for this business type.',
            ], 403));
        }
    }
}
```

- [ ] **Step 4: Run access test**

Run: php artisan test tests/Feature/Api/RetailPosBusinessTypeAccessTest.php
Expected: PASS for retail/repair/both access matrix.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php app/Http/Controllers/Api/RetailPosController.php tests/Feature/Api/RetailPosBusinessTypeAccessTest.php
git commit -m "feat(api): add retail POS endpoints with business-type guards"
```

### Task 4: Implement Retail POS Checkout to Existing Order Tables

**Files:**
- Modify: app/Http/Controllers/Api/RetailPosController.php
- Create: app/Services/RetailPosCheckoutService.php
- Test: tests/Feature/Api/RetailPosCheckoutTest.php

- [ ] **Step 1: Write failing feature test for retail checkout persistence**

```php
public function test_retail_checkout_creates_paid_completed_order(): void
{
    $user = User::factory()->create(['shop_owner_id' => $this->bothOwner->id]);
    $product = Product::factory()->create([
        'shop_owner_id' => $this->bothOwner->id,
        'price' => 1299,
        'stock_quantity' => 10,
    ]);

    $this->actingAs($user, 'user');

    $response = $this->postJson('/api/retail-pos/checkout', [
        'idempotency_key' => 'retail-pos-001-abc',
        'customer_name' => 'Walk In Buyer',
        'customer_phone' => '09171234567',
        'payment_method' => 'cash',
        'payment_reference' => null,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 1299,
        ]],
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('orders', [
        'shop_owner_id' => $this->bothOwner->id,
        'customer_name' => 'Walk In Buyer',
        'payment_status' => 'paid',
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: php artisan test tests/Feature/Api/RetailPosCheckoutTest.php
Expected: FAIL because checkout logic and service are not implemented.

- [ ] **Step 3: Implement checkout service with transaction and stock deduction**

```php
class RetailPosCheckoutService
{
    public function checkout(int $shopOwnerId, array $payload, int $actorId): Order
    {
        return DB::transaction(function () use ($shopOwnerId, $payload, $actorId) {
            $orderNumber = $this->generateRetailPosOrderNumber();
            $subtotal = collect($payload['items'])->sum(fn ($item) => ((float) $item['unit_price']) * ((int) $item['qty']));

            $order = Order::create([
                'shop_owner_id' => $shopOwnerId,
                'customer_id' => null,
                'order_number' => $orderNumber,
                'total_amount' => $subtotal,
                'shipping_fee' => 0,
                'vat_rate' => 12,
                'vat_amount' => round($subtotal * (12 / 112), 2),
                'status' => 'completed',
                'customer_name' => $payload['customer_name'],
                'customer_email' => $payload['customer_email'] ?? null,
                'customer_phone' => $payload['customer_phone'] ?? null,
                'customer_address' => 'Walk-in POS',
                'payment_method' => $payload['payment_method'],
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            foreach ($payload['items'] as $line) {
                $product = Product::query()->where('shop_owner_id', $shopOwnerId)->lockForUpdate()->findOrFail((int) $line['product_id']);

                $qty = (int) $line['qty'];
                if ((int) $product->stock_quantity < $qty) {
                    throw ValidationException::withMessages(['items' => ['Insufficient stock for selected item.']]);
                }

                $unitPrice = (float) $line['unit_price'];
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_slug' => $product->slug,
                    'price' => $unitPrice,
                    'quantity' => $qty,
                    'subtotal' => round($unitPrice * $qty, 2),
                    'size' => $line['size'] ?? null,
                    'color' => $line['color'] ?? null,
                    'product_image' => $line['image'] ?? $product->main_image,
                ]);

                $product->decrement('stock_quantity', $qty);
            }

            return $order->fresh(['items']);
        });
    }

    private function generateRetailPosOrderNumber(): string
    {
        return 'RPOS-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: Wire controller checkout endpoint with request validation**

```php
public function checkout(Request $request, RetailPosCheckoutService $service)
{
    $validated = $request->validate([
        'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        'customer_name' => ['required', 'string', 'max:255'],
        'customer_phone' => ['nullable', 'string', 'max:30'],
        'customer_email' => ['nullable', 'email', 'max:255'],
        'payment_method' => ['required', 'string', 'in:cash,gcash,card'],
        'payment_reference' => ['nullable', 'string', 'max:255'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
        'items.*.qty' => ['required', 'integer', 'min:1'],
        'items.*.unit_price' => ['required', 'numeric', 'min:0.01'],
    ]);

    if (in_array($validated['payment_method'], ['gcash', 'card'], true)
        && trim((string) ($validated['payment_reference'] ?? '')) === '') {
        throw ValidationException::withMessages([
            'payment_reference' => ['Reference is required for GCash and Card payments.'],
        ]);
    }

    $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
    $this->assertRetailOrBoth($shopOwnerId);

    $order = $service->checkout($shopOwnerId, $validated, $this->resolveActorAuditUserId());

    return response()->json([
        'success' => true,
        'order_id' => (int) $order->id,
        'order_number' => (string) $order->order_number,
    ], 201);
}
```

- [ ] **Step 5: Run checkout tests**

Run: php artisan test tests/Feature/Api/RetailPosCheckoutTest.php
Expected: PASS for paid/completed persistence, payment reference validation, stock deduction, and shop scoping.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/RetailPosController.php app/Services/RetailPosCheckoutService.php tests/Feature/Api/RetailPosCheckoutTest.php
git commit -m "feat(retail-pos): implement walk-in retail checkout to orders"
```

### Task 5: Add Retail POS History and Receipt in UI

**Files:**
- Modify: app/Http/Controllers/Api/RetailPosController.php
- Modify: resources/js/Pages/ERP/repairer/POS.tsx
- Test: tests/Feature/Api/RetailPosHistoryTest.php
- Test: resources/js/Pages/ERP/repairer/__tests__/POS.retail-history.test.tsx

- [ ] **Step 1: Write failing backend history test**

```php
public function test_history_returns_only_retail_pos_orders_for_actor_shop(): void
{
    Order::factory()->create([
        'shop_owner_id' => $this->bothOwner->id,
        'order_number' => 'RPOS-20260408-0001',
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);

    Order::factory()->create([
        'shop_owner_id' => $this->bothOwner->id,
        'order_number' => 'ORD-20260408-0002',
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);

    $this->actingAs($this->bothUser, 'user');

    $response = $this->getJson('/api/retail-pos/history');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.order_number', 'RPOS-20260408-0001');
}
```

- [ ] **Step 2: Implement history and receipt endpoints**

```php
public function history(Request $request)
{
    $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
    $this->assertRetailOrBoth($shopOwnerId);

    $limit = max(1, min((int) $request->query('limit', 200), 500));

    $rows = Order::query()
        ->where('shop_owner_id', $shopOwnerId)
        ->where('order_number', 'like', 'RPOS-%')
        ->with('items')
        ->orderByDesc('id')
        ->limit($limit)
        ->get();

    return response()->json(['success' => true, 'data' => $rows]);
}

public function receipt(Order $order)
{
    $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
    $this->assertRetailOrBoth($shopOwnerId);

    abort_if((int) $order->shop_owner_id !== $shopOwnerId, 404);
    abort_if(!str_starts_with((string) $order->order_number, 'RPOS-'), 404);

    $order->load('items');

    return response()->json([
        'success' => true,
        'data' => [
            'order_number' => $order->order_number,
            'paid_at' => optional($order->paid_at)->toDateTimeString(),
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'payment_method' => $order->payment_method,
            'totals' => [
                'subtotal' => (float) ($order->total_amount ?? 0),
                'vat' => (float) ($order->vat_amount ?? 0),
                'shipping' => (float) ($order->shipping_fee ?? 0),
                'grand_total' => (float) (($order->total_amount ?? 0) + ($order->vat_amount ?? 0) + ($order->shipping_fee ?? 0)),
            ],
            'items' => $order->items,
        ],
    ]);
}
```

- [ ] **Step 3: Add retail history modal rendering in POS page**

```tsx
const [retailHistory, setRetailHistory] = useState<any[]>([]);
const [isRetailHistoryOpen, setIsRetailHistoryOpen] = useState(false);

const loadRetailHistory = async () => {
  const response = await retailPosApi.history(200);
  const rows = Array.isArray(response?.data?.data) ? response.data.data : [];
  setRetailHistory(rows);
};

useEffect(() => {
  if (posMode === "retail" && isRetailHistoryOpen) {
    loadRetailHistory();
  }
}, [posMode, isRetailHistoryOpen]);
```

- [ ] **Step 4: Run targeted tests**

Run: php artisan test tests/Feature/Api/RetailPosHistoryTest.php
Expected: PASS with RPOS-only scoped history and guarded receipt.

Run: pnpm vitest resources/js/Pages/ERP/repairer/__tests__/POS.retail-history.test.tsx
Expected: PASS with retail history modal behavior.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RetailPosController.php resources/js/Pages/ERP/repairer/POS.tsx tests/Feature/Api/RetailPosHistoryTest.php resources/js/Pages/ERP/repairer/__tests__/POS.retail-history.test.tsx
git commit -m "feat(retail-pos): add retail history and receipt flow"
```

### Task 6: Full Verification and Documentation Updates

**Files:**
- Modify: docs/P4-STATUS.md
- Modify: docs/P4-IMPLEMENTATION-SUMMARY.md

- [ ] **Step 1: Run backend regression pack**

Run: php artisan test tests/Feature/Api/RetailPosBusinessTypeAccessTest.php tests/Feature/Api/RetailPosCheckoutTest.php tests/Feature/Api/RetailPosHistoryTest.php tests/Feature/Api/RepairPos* --stop-on-failure
Expected: PASS with no repair POS regressions.

- [ ] **Step 2: Run frontend regression pack**

Run: pnpm vitest resources/js/Pages/ERP/repairer/__tests__/POS.*.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.pos-visibility.test.tsx
Expected: PASS for mode gating and sidebar matrix.

- [ ] **Step 3: Update implementation docs**

```md
## ERP POS Unified Repair-Retail
- Added business-type-aware POS mode gating.
- Added retail walk-in POS checkout to existing orders and order_items.
- Added retail receipt and history endpoints.
- Preserved repair POS flow and refund behaviors.
```

- [ ] **Step 4: Commit verification and docs**

```bash
git add docs/P4-STATUS.md docs/P4-IMPLEMENTATION-SUMMARY.md
git commit -m "docs: record unified ERP POS repair-retail rollout"
```

---

## Self-Review

### 1. Spec coverage check
- Single POS page with repair plus retail mode: covered in Task 1.
- Business-type UI rules retail, repair, both: covered in Task 1 and Task 2.
- Business-type backend enforcement: covered in Task 3.
- Retail checkout to orders plus order_items and immediate paid/completed: covered in Task 4.
- Retail v1 no refund and includes receipt plus history: covered in Task 5.
- Regression safety for repair: covered in Task 6.

### 2. Placeholder scan
- No TODO, TBD, or deferred placeholders used.
- Each implementation step includes explicit code or explicit command.

### 3. Type and naming consistency
- posMode values are repair and retail consistently.
- business-type access code uses BUSINESS_TYPE_FORBIDDEN_MODE consistently.
- retail POS history identification uses RPOS- prefix consistently.
