# Repair Performance and Unified Delivery Revenue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep repair job processing responsive and recognize paid shop-owned retail and repair delivery fees once, separately from VAT-exclusive product/service revenue.

**Architecture:** Reuse the existing repair/logistics models by eager-loading all handoff state in the job-order query and letting `RepairDeliveryService` consume the loaded relation. Put the two frontend revenue formulas in one small utility used by the retail and repair cards, while backend dashboard and invoice code use the same payment/carrier/lock rules. Preserve existing refund behavior, and classify third-party courier charges as non-revenue.

**Tech Stack:** Laravel 11/PHPUnit, React 18/TypeScript, Inertia, Axios, Vitest/Testing Library, Eloquent.

---

## File Map

- Create `resources/js/utils/deliveryRevenue.ts`: pure retail and repair revenue calculations.
- Create `resources/js/utils/__tests__/deliveryRevenue.test.ts`: focused delivery-revenue behavior tests.
- Create `tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php`: retail auto-invoice shipping regression.
- Modify `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`: background refresh, Inertia chat navigation, repair delivery fields, and combined repair revenue.
- Modify `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`: refresh/visibility/overlap/navigation regressions.
- Modify `app/Models/RepairRequest.php`: scoped logistics shipment relation and paid shop-owned fee breakdown.
- Modify `app/Services/RepairDeliveryService.php`: reuse eager-loaded shipment state.
- Modify `app/Http/Controllers/Api/RepairWorkflowController.php`: eager-load handoff state and remove an unused duplicate invoice generator.
- Modify `tests/Feature/Repair/RepairIntakeHandoffTest.php`: constant-query regression for the repair job-order endpoint.
- Modify `resources/js/Pages/ERP/STAFF/JobOrders.tsx`: include eligible retail shipping in the revenue card.
- Modify `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`: verify retail card eligibility inputs.
- Modify `app/Http/Controllers/UserSide/CheckoutController.php`: include checkout shipping in the generated retail invoice.
- Modify `app/Http/Controllers/Api/RepairRequestController.php`: include paid locked repair delivery fees in the picked-up invoice.
- Modify `tests/Feature/Repair/RepairReturnHandoffTest.php`: repair invoice delivery-line regression.
- Modify `app/Http/Controllers/ShopOwner/DashboardController.php`: calculate product/service revenue excluding VAT, then add paid shop-owned delivery fees once.
- Modify `tests/Feature/ShopOwnerDashboardRevenueTest.php`: combined retail/repair delivery-revenue regressions.

Do not stage or alter the pre-existing customer-address edits or the user-owned untracked migration:

- `app/Http/Controllers/UserController.php`
- `tests/Feature/UserSide/UserAddressCoordinateTest.php`
- `database/migrations/2026_07_26_000002_add_job_reference_to_finance_invoices.php`

### Task 1: Make repair refresh non-blocking

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`

- [ ] **Step 1: Write the failing refresh and navigation tests**

Add `router.visit` to the existing Inertia mock and capture the interval callback. Add tests that:

```tsx
it('keeps the current table visible and prevents overlapping background refreshes', async () => {
  // Complete the initial request, invoke the captured interval twice while the
  // second repair request is unresolved, and assert the row remains visible
  // and only one background repair request was started.
});

it('skips polling while the tab is hidden', async () => {
  Object.defineProperty(document, 'visibilityState', {
    configurable: true,
    value: 'hidden',
  });
  // Invoke the interval callback and assert neither polling request runs.
});

it('opens accepted repair chat through Inertia', async () => {
  mocks.post.mockResolvedValueOnce({
    data: { success: true, conversation_id: 99 },
  });
  // Accept the repair and assert router.visit received the repairer-support URL.
});
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```powershell
npm run test:frontend -- resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
```

Expected: FAIL because interval refreshes toggle `isLoading`, overlapping requests are allowed, hidden tabs still poll, and chat uses `window.location.href`.

- [ ] **Step 3: Implement the minimal refresh changes**

In `JobOrdersRepair.tsx`:

```tsx
import { Head, router, usePage } from "@inertiajs/react";

const isOrdersRequestInFlightRef = useRef(false);

const fetchOrders = async (showLoading = false) => {
  if (isOrdersRequestInFlightRef.current) return;
  isOrdersRequestInFlightRef.current = true;

  if (showLoading) setIsLoading(true);
  try {
    // Keep the existing request and mapping.
  } catch (error) {
    console.error('Failed to fetch repair requests:', error);
    if (showLoading) setError('Failed to load repair requests');
  } finally {
    isOrdersRequestInFlightRef.current = false;
    if (showLoading) setIsLoading(false);
  }
};
```

Call `fetchOrders(true)` only for the first load. In the ten-second callback, return immediately unless `document.visibilityState === 'visible'`, then call `fetchOrders()` and `fetchRepairerRefundQueue()`. Replace the chat assignment with:

```tsx
router.visit(`/erp/staff/repairer-support?conversation_id=${createdConversationId}`);
```

- [ ] **Step 4: Run the focused frontend test**

Run:

```powershell
npm run test:frontend -- resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Commit only Task 1**

```powershell
git add -- resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
git commit -m "fix: keep repair job refresh responsive"
```

### Task 2: Eliminate per-repair handoff queries

**Files:**
- Modify: `tests/Feature/Repair/RepairIntakeHandoffTest.php`
- Modify: `app/Models/RepairRequest.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`

- [ ] **Step 1: Write the failing query-count regression**

Add a test that creates one assigned shop-pickup repair with a shipment, calls `/api/repairer/repairs`, records queries touching `shipments`, `shipment_legs`, `handoff_proofs`, or `delivery_events`, then adds a second equivalent repair and calls the endpoint again:

```php
$this->assertSame(
    $oneRepairLogisticsQueryCount,
    $twoRepairLogisticsQueryCount,
    'Adding a repair must not add per-repair logistics queries.',
);
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairIntakeHandoffTest.php --filter=job_order_handoff_queries
```

Expected: FAIL because each `intakeHandoff()`/`returnHandoff()` performs its own shipment query.

- [ ] **Step 3: Add the scoped Eloquent relationship**

In `RepairRequest.php`:

```php
use App\Models\Logistics\Shipment;
use Illuminate\Database\Eloquent\Relations\HasMany;

public function logisticsShipments(): HasMany
{
    return $this->hasMany(Shipment::class, 'source_id')
        ->where('source_type', 'repair_request');
}
```

- [ ] **Step 4: Eager-load the relationship in all three job-order query branches**

Add these relations to each `RepairRequest::with(...)` call in `myAssignedRepairs()`:

```php
'logisticsShipments.legs.proofs',
'logisticsShipments.events',
```

- [ ] **Step 5: Reuse loaded handoff state with a direct-query fallback**

In `RepairDeliveryService::handoffState()`:

```php
$shipment = $repair->relationLoaded('logisticsShipments')
    ? $repair->logisticsShipments->firstWhere('purpose', $purpose)
    : Shipment::query()
        ->with(['legs.proofs', 'events'])
        ->where('source_type', 'repair_request')
        ->where('source_id', $repair->id)
        ->where('purpose', $purpose)
        ->first();
```

Keep the existing leg, proof, approval, and event formatting unchanged.

- [ ] **Step 6: Run the focused handoff tests**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairIntakeHandoffTest.php tests/Feature/Repair/RepairReturnHandoffTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit only Task 2**

```powershell
git add -- app/Models/RepairRequest.php app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/Repair/RepairIntakeHandoffTest.php
git commit -m "perf: batch repair handoff loading"
```

### Task 3: Apply one frontend delivery-revenue rule

**Files:**
- Create: `resources/js/utils/deliveryRevenue.ts`
- Create: `resources/js/utils/__tests__/deliveryRevenue.test.ts`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Modify: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`

- [ ] **Step 1: Write the failing pure revenue tests**

Cover:

```ts
expect(calculateRetailRevenue({
  productRevenueExVat: 100,
  shippingFee: 20,
  refundedAmount: 0,
  orderGrandTotal: 132,
  paymentStatus: 'paid',
  carrierCompany: 'Shop-owned logistics',
})).toBe(120);

// Third-party and unpaid shipping return product revenue only.
// A full refund returns zero; a partial item refund keeps paid shop delivery.

expect(calculateRepairRevenue({
  serviceGrossAmount: 1120,
  serviceNetAmount: 1000,
  totalPaidAmount: 1240,
  refundedAmount: 0,
  paymentStatus: 'completed',
  paymentPolicy: 'full_upfront',
  intakeMethod: 'shop_pickup',
  intakeFee: 50,
  intakeLocked: true,
  returnMethod: 'shop_delivery',
  returnFee: 70,
  returnLocked: true,
})).toBe(1120);

// Unlocked/customer-arranged fees are excluded; full refunds return zero.
```

- [ ] **Step 2: Run the utility tests and verify RED**

Run:

```powershell
npm run test:frontend -- resources/js/utils/__tests__/deliveryRevenue.test.ts
```

Expected: FAIL because the utility does not exist.

- [ ] **Step 3: Implement the two pure calculations**

Create `deliveryRevenue.ts` with:

```ts
const roundMoney = (value: number) => Math.round(Math.max(0, value) * 100) / 100;
const isPaid = (status: string) => ['paid', 'completed'].includes(status.trim().toLowerCase());
const isShopOwned = (carrier: string) => carrier.trim().toLowerCase() === 'shop-owned logistics';

export function calculateRetailRevenue(input: RetailRevenueInput): number {
  const productRevenue = Math.max(
    0,
    input.productRevenueExVat - Math.min(input.productRevenueExVat, input.refundedAmount),
  );
  const fullyRefunded = input.orderGrandTotal > 0
    && input.refundedAmount >= input.orderGrandTotal - 0.01;
  const deliveryRevenue = isPaid(input.paymentStatus)
    && isShopOwned(input.carrierCompany)
    && !fullyRefunded
      ? input.shippingFee
      : 0;

  return roundMoney(productRevenue + deliveryRevenue);
}

export function calculateRepairRevenue(input: RepairRevenueInput): number {
  const paidDelivery = roundMoney(
    (input.intakeMethod === 'shop_pickup' && input.intakeLocked ? input.intakeFee : 0)
    + (input.returnMethod === 'shop_delivery' && input.returnLocked ? input.returnFee : 0),
  );
  const fallbackServicePaid = input.paymentStatus === 'completed'
    ? input.serviceGrossAmount
    : (['paid', 'partially_paid'].includes(input.paymentStatus)
      ? (input.paymentPolicy === 'full_upfront'
        ? input.serviceGrossAmount
        : input.serviceGrossAmount * 0.5)
      : 0);
  const grossPaid = input.totalPaidAmount > 0
    ? input.totalPaidAmount
    : fallbackServicePaid + paidDelivery;
  const netCollected = Math.max(0, grossPaid - input.refundedAmount);
  const realizedDelivery = Math.min(paidDelivery, netCollected);
  const serviceCollected = Math.max(0, netCollected - realizedDelivery);
  const serviceRatio = input.serviceGrossAmount > 0
    ? Math.min(1, serviceCollected / input.serviceGrossAmount)
    : 0;

  return roundMoney((input.serviceNetAmount * serviceRatio) + realizedDelivery);
}
```

Define narrow exported input types above these functions.

- [ ] **Step 4: Wire the retail and repair cards to the utility**

Retail:

```tsx
const refundedAmount = getCombinedSucceededRefundAmount(o, o.grand_total);
return sum + calculateRetailRevenue({
  productRevenueExVat: parseAmount(o.total_amount),
  shippingFee: parseAmount(o.shipping_fee),
  refundedAmount,
  orderGrandTotal: parseAmount(o.grand_total),
  paymentStatus: o.paymentStatus,
  carrierCompany: o.carrierCompany || '',
});
```

Repair mapping must retain `intake_delivery_fee`, `return_delivery_fee`, and both logistics lock timestamps, then the reducer calls `calculateRepairRevenue(...)`.

Change both card descriptions to say that paid shop-owned delivery is included and VAT is excluded from products/services.

- [ ] **Step 5: Run the focused frontend tests**

Run:

```powershell
npm run test:frontend -- resources/js/utils/__tests__/deliveryRevenue.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Commit only Task 3**

```powershell
git add -- resources/js/utils/deliveryRevenue.ts resources/js/utils/__tests__/deliveryRevenue.test.ts resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
git commit -m "feat: include shop delivery in module revenue"
```

### Task 4: Put collected delivery charges on retail and repair invoices

**Files:**
- Create: `tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php`
- Modify: `tests/Feature/Repair/RepairReturnHandoffTest.php`
- Modify: `app/Models/RepairRequest.php`
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`

- [ ] **Step 1: Write the failing retail invoice test**

Use the container to resolve `CheckoutController`, invoke its protected `autoGenerateInvoice()` through `ReflectionMethod`, and assert:

```php
$this->assertSame('132.00', $invoice->total);
$this->assertSame('20.00', number_format((float) data_get($invoice->meta, 'shipping_fee'), 2, '.', ''));
$this->assertDatabaseHas('finance_invoice_items', [
    'invoice_id' => $invoice->id,
    'description' => 'Shipping Fee',
    'amount' => 20,
]);
```

The fixture uses product subtotal `100`, VAT `12`, and shipping `20`.

- [ ] **Step 2: Write the failing repair invoice test**

Extend `RepairReturnHandoffTest` with a completed shop-delivery handoff whose repair has:

```php
'final_total' => 1000,
'total_paid_amount' => 1120,
'intake_delivery_method' => 'shop_pickup',
'intake_delivery_fee' => 50,
'intake_logistics_locked_at' => now(),
'return_delivery_method' => 'shop_delivery',
'return_delivery_fee' => 70,
```

After customer confirmation, assert invoice total `1120`, metadata values `1000/50/70/120/1120`, and separate `Shop-owned intake pickup` and `Shop-owned return delivery` lines.

- [ ] **Step 3: Run both invoice tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php tests/Feature/Repair/RepairReturnHandoffTest.php
```

Expected: FAIL because both auto-generated invoices currently contain service/product totals only.

- [ ] **Step 4: Add the repair model fee breakdown**

In `RepairRequest.php`:

```php
public function paidShopOwnedDeliveryFees(): array
{
    $intake = $this->intake_delivery_method === 'shop_pickup'
        && $this->intake_logistics_locked_at !== null
            ? max(0, (float) $this->intake_delivery_fee)
            : 0.0;
    $return = $this->return_delivery_method === 'shop_delivery'
        && $this->return_logistics_locked_at !== null
            ? max(0, (float) $this->return_delivery_fee)
            : 0.0;

    return [
        'intake' => round($intake, 2),
        'return' => round($return, 2),
        'total' => round($intake + $return, 2),
    ];
}
```

- [ ] **Step 5: Correct the retail auto-generated invoice**

In `CheckoutController::autoGenerateInvoice()` calculate:

```php
$itemSubtotal = max(0.0, (float) $order->total_amount);
$shippingFee = max(0.0, (float) $order->shipping_fee);
$vatAmount = $order->vat_amount !== null
    ? max(0.0, (float) $order->vat_amount)
    : round($itemSubtotal * 0.12, 2);
$grandTotal = round($itemSubtotal + $vatAmount + $shippingFee, 2);
```

Use `$grandTotal` for invoice total and payment activity, `$vatAmount` for tax, add metadata keys `subtotal_amount`, `shipping_fee`, `vat_amount`, `grand_total`, and create one zero-tax `Shipping Fee` invoice item when the fee is positive.

- [ ] **Step 6: Correct the picked-up repair invoice**

In `RepairRequestController::autoGenerateInvoiceForPickedUpRepair()` use the model fee breakdown. Store metadata keys `service_amount`, `intake_delivery_fee`, `return_delivery_fee`, `shipping_fee`, and `grand_total`; set invoice total to service plus delivery; and create each positive delivery line with zero tax.

Delete the unused duplicate `generateRepairInvoiceReference()` and `autoGenerateInvoiceForPickedUpRepair()` methods and now-unused invoice imports from `RepairWorkflowController`; `rg` confirms that controller never calls them.

- [ ] **Step 7: Run both invoice tests**

Run:

```powershell
php artisan test tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php tests/Feature/Repair/RepairReturnHandoffTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit only Task 4**

```powershell
git add -- app/Models/RepairRequest.php app/Http/Controllers/UserSide/CheckoutController.php app/Http/Controllers/Api/RepairRequestController.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php tests/Feature/Repair/RepairReturnHandoffTest.php
git commit -m "fix: record paid delivery charges on invoices"
```

### Task 5: Correct the combined shop-owner revenue

**Files:**
- Modify: `tests/Feature/ShopOwnerDashboardRevenueTest.php`
- Modify: `app/Http/Controllers/ShopOwner/DashboardController.php`

- [ ] **Step 1: Write failing combined-revenue tests**

Add cases proving:

```php
// 100 product revenue + 20 paid shop-owned shipping = 120.
// The same third-party or unpaid shipping contributes 0 delivery revenue.
// A fully refunded retail order contributes 0.
// 1000/1.12 repair service revenue + 50 intake + 70 return delivery.
// Clearing a repair logistics lock removes that fee from revenue.
```

Use `forceFill(['carrier_company' => ...])->save()` for retail fixtures because the legacy `Order::$fillable` list does not include carrier fields.

- [ ] **Step 2: Run the dashboard test and verify RED**

Run:

```powershell
php artisan test tests/Feature/ShopOwnerDashboardRevenueTest.php
```

Expected: FAIL because retail shipping is VAT-scaled without carrier/payment classification and repair delivery is VAT-scaled with service revenue.

- [ ] **Step 3: Separate retail product and delivery revenue**

In `computeRetailNetRevenue()` select item subtotal, shipping fee, gross total, payment status, and carrier. Per order:

```php
$productRevenue = max(0.0, $itemSubtotal - min($itemSubtotal, $totalRefunded));
$fullyRefunded = $grossAmount > 0 && $totalRefunded >= ($grossAmount - 0.01);
$deliveryRevenue = in_array($paymentStatus, ['paid', 'completed'], true)
    && strtolower(trim($carrierCompany)) === 'shop-owned logistics'
    && ! $fullyRefunded
        ? $shippingFee
        : 0.0;
$netRevenue += $productRevenue + $deliveryRevenue;
```

This uses the stored VAT-exclusive `total_amount` directly and adds eligible delivery outside VAT.

- [ ] **Step 4: Separate repair service and delivery revenue**

Update `repairRevenueExpression()` with SQL `CASE` fragments for:

```text
paid_delivery =
  locked shop_pickup intake fee
  + locked shop_delivery return fee

net_collected =
  max(total_paid_amount - total_refunded_amount, 0)
  or the existing payment-status fallback + paid_delivery

recognized_delivery = min(paid_delivery, net_collected)
service_collected = max(net_collected - recognized_delivery, 0)
revenue = service_collected / 1.12 + recognized_delivery
```

Use portable `CASE` expressions rather than `GREATEST`/`LEAST` so SQLite tests and MySQL production behave the same.

- [ ] **Step 5: Run the dashboard regression**

Run:

```powershell
php artisan test tests/Feature/ShopOwnerDashboardRevenueTest.php
```

Expected: PASS, including the existing no-delivery revenue expectations.

- [ ] **Step 6: Commit only Task 5**

```powershell
git add -- app/Http/Controllers/ShopOwner/DashboardController.php tests/Feature/ShopOwnerDashboardRevenueTest.php
git commit -m "fix: combine shop-owned delivery revenue correctly"
```

### Task 6: Verify the complete branch

**Files:**
- Verify only; do not stage unrelated worktree files.

- [ ] **Step 1: Run all focused regressions**

```powershell
php artisan test tests/Feature/Repair/RepairIntakeHandoffTest.php tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php tests/Feature/ShopOwnerDashboardRevenueTest.php
npm run test:frontend -- resources/js/utils/__tests__/deliveryRevenue.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
```

Expected: PASS.

- [ ] **Step 2: Run full backend and frontend suites**

```powershell
php artisan test
npm run test:frontend
```

Expected: PASS with zero failures.

- [ ] **Step 3: Build production assets**

```powershell
npm run build
```

Expected: exit code `0`.

- [ ] **Step 4: Inspect repository state**

```powershell
git diff --check
git status --short
git log --oneline -8
```

Expected: no whitespace errors; only the pre-existing customer-address changes, the untracked migration, and generated `public/build` changes remain outside the focused source commits.

- [ ] **Step 5: Follow branch-completion workflow**

Use `superpowers:finishing-a-development-branch`. Do not push until the user explicitly chooses the push/PR option.
