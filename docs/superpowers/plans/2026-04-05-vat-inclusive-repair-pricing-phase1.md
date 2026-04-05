# VAT-Inclusive Repair Pricing Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove repair-flow double VAT charging by treating displayed repair prices as VAT-inclusive for new transactions, while preserving legacy record behavior.

**Architecture:** Introduce a shared VAT-inclusive calculator on backend and shared repair pricing helpers on frontend. New records are explicitly marked as `vat_inclusive`, and all read paths branch by tax mode: inclusive mode uses extraction ($VAT = total * 12 / 112$), legacy mode keeps old add-on behavior. Job Order Repair pages render explicit subtotal/VAT/grand total breakdown lines from normalized pricing data.

**Tech Stack:** Laravel (PHP 8+), PHPUnit, React + TypeScript, Vitest

---

## File Structure Map

### Backend pricing core
- Create: `app/Support/Tax/VatInclusiveCalculator.php`
  - Single responsibility: compute VAT/net from inclusive totals with deterministic 2-decimal rounding.
- Create: `tests/Unit/Support/Tax/VatInclusiveCalculatorTest.php`
  - Unit coverage for extraction math and rounding edge cases.

### Backend repair checkout + payloads
- Modify: `app/Services/RepairPosPaymentService.php`
  - Use inclusive due totals; persist extracted VAT/net; set `metadata.tax_mode = vat_inclusive`.
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
  - Fix retry-payment due math (no extra +12 on top).
  - Add tax-mode-aware payload values for customer repair summaries.
- Create: `tests/Feature/RepairVatInclusivePayloadTest.php`
  - Coverage for my-repairs payload + retry payment payload consistency.
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`
  - Update expected amounts for inclusive math and assert tax mode marker.

### Frontend shared helpers
- Create: `resources/js/utils/repairPricing.ts`
  - Shared frontend pricing normalization for inclusive vs legacy records.
- Create: `resources/js/utils/__tests__/repairPricing.test.ts`
  - Unit tests for breakdown generation and fallback behavior.

### Frontend repair pages
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
  - Use normalized pricing and always show breakdown block in details modal.
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx`
  - Keep parity with repairer job order breakdown rendering.
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
  - Use inclusive totals and extracted VAT display.
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
  - Same VAT-inclusive due behavior as repairer POS.
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
  - Show inclusive total without adding VAT twice.
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
  - Tax-mode-aware VAT and grand total display.

---

### Task 1: Add Backend VAT-Inclusive Calculator

**Files:**
- Create: `app/Support/Tax/VatInclusiveCalculator.php`
- Create: `tests/Unit/Support/Tax/VatInclusiveCalculatorTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit\Support\Tax;

use App\Support\Tax\VatInclusiveCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VatInclusiveCalculatorTest extends TestCase
{
    #[Test]
    public function it_extracts_vat_and_net_from_inclusive_total(): void
    {
        $breakdown = VatInclusiveCalculator::extract(112.00, 12.0);

        $this->assertSame('112.00', number_format($breakdown['total'], 2, '.', ''));
        $this->assertSame('12.00', number_format($breakdown['vat'], 2, '.', ''));
        $this->assertSame('100.00', number_format($breakdown['net'], 2, '.', ''));
    }

    #[Test]
    public function it_handles_rounding_consistently_for_non_integer_totals(): void
    {
        $breakdown = VatInclusiveCalculator::extract(500.00, 12.0);

        $this->assertSame('500.00', number_format($breakdown['total'], 2, '.', ''));
        $this->assertSame('53.57', number_format($breakdown['vat'], 2, '.', ''));
        $this->assertSame('446.43', number_format($breakdown['net'], 2, '.', ''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Support/Tax/VatInclusiveCalculatorTest.php`
Expected: FAIL with class `App\Support\Tax\VatInclusiveCalculator` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Support\Tax;

final class VatInclusiveCalculator
{
    public static function extract(float $inclusiveTotal, float $vatRatePercent = 12.0): array
    {
        $total = round(max(0.0, $inclusiveTotal), 2);
        $rate = max(0.0, $vatRatePercent);

        if ($total <= 0 || $rate <= 0) {
            return [
                'total' => $total,
                'vat' => 0.0,
                'net' => $total,
            ];
        }

        $vat = round($total * ($rate / (100 + $rate)), 2);
        $net = round($total - $vat, 2);

        return [
            'total' => $total,
            'vat' => $vat,
            'net' => $net,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Support/Tax/VatInclusiveCalculatorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Tax/VatInclusiveCalculator.php tests/Unit/Support/Tax/VatInclusiveCalculatorTest.php
git commit -m "feat: add VAT-inclusive calculator utility"
```

---

### Task 2: Apply Inclusive Math in Repair POS Checkout

**Files:**
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] **Step 1: Write failing feature assertion for inclusive deposit**

```php
#[Test]
public function deposit_due_uses_inclusive_split_and_extracts_vat(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $customer = \App\Models\User::factory()->create();
    $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $repair = \App\Models\RepairRequest::create([
        'request_id' => 'REP-VAT-INC-001',
        'customer_name' => $customer->name,
        'email' => $customer->email,
        'phone' => '09175550000',
        'shoe_type' => 'Sneakers',
        'description' => 'Inclusive VAT test',
        'shop_owner_id' => $shopOwner->id,
        'user_id' => $customer->id,
        'images' => json_encode([]),
        'total' => 1000,
        'final_total' => 1000,
        'status' => 'ready_for_pickup',
        'payment_policy' => 'deposit_50',
        'payment_policy_snapshot' => 'deposit_50',
        'payment_status' => 'pending',
    ]);

    $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => $repair->id,
        'due_type' => 'deposit',
        'customer_type' => 'registered',
        'customer_id' => $customer->id,
        'idempotency_key' => 'vat-inclusive-pos-001',
        'payment_lines' => [
            ['tender_type' => 'cash', 'amount' => 500],
        ],
    ]);

    $response->assertOk();

    $transaction = \App\Models\PosTransaction::query()->findOrFail((int) $response->json('transaction_id'));

    $this->assertSame('500.00', number_format((float) $transaction->total_amount, 2, '.', ''));
    $this->assertSame('53.57', number_format((float) $transaction->tax_amount, 2, '.', ''));
    $this->assertSame('446.43', number_format((float) $transaction->subtotal, 2, '.', ''));
    $this->assertSame('vat_inclusive', (string) data_get($transaction->metadata, 'tax_mode'));
}
```

- [ ] **Step 2: Run targeted feature tests to verify failure**

Run: `php artisan test --filter=RepairPosPaymentFlowTest`
Expected: FAIL because current checkout computes `500 + 12% = 560` behavior.

- [ ] **Step 3: Implement inclusive due extraction in service**

```php
use App\Support\Tax\VatInclusiveCalculator;

$totalInclusive = (float) ($repair->final_total ?? $repair->total ?? 0);

$dueTotal = $normalizedPolicy === 'full_upfront'
    ? round($totalInclusive, 2)
    : round($totalInclusive * 0.5, 2);

$breakdown = VatInclusiveCalculator::extract($dueTotal, self::VAT_RATE_PERCENT);
$dueSubtotal = $breakdown['net'];
$vatAmount = $breakdown['vat'];
$dueAmount = $breakdown['total'];

// Persist marker for deterministic read behavior.
'metadata' => [
    'vat_rate' => self::VAT_RATE_PERCENT,
    'tax_mode' => 'vat_inclusive',
],
```

- [ ] **Step 4: Re-run target test suite**

Run: `php artisan test --filter=RepairPosPaymentFlowTest`
Expected: PASS, including new inclusive assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairPosPaymentService.php tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "feat: use VAT-inclusive repair POS due calculations"
```

---

### Task 3: Fix Repair API Payload Math and Retry Session Amounts

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Create: `tests/Feature/RepairVatInclusivePayloadTest.php`

- [ ] **Step 1: Write failing feature tests for payload totals**

```php
<?php

namespace Tests\Feature;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairVatInclusivePayloadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function my_repairs_payload_uses_extracted_vat_and_keeps_grand_total_equal_to_final_total(): void
    {
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        RepairRequest::create([
            'request_id' => 'REP-MY-INC-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000022',
            'shoe_type' => 'Running Shoes',
            'description' => 'Payload math test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'pricing_breakdown' => ['tax_mode' => 'vat_inclusive', 'final_total' => 1000],
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/repairs/my-repairs');
        $response->assertOk();

        $first = $response->json('data.0');
        $this->assertSame(1000.0, (float) $first['grand_total']);
        $this->assertSame(107.14, round((float) $first['vat_amount'], 2));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairVatInclusivePayloadTest.php`
Expected: FAIL because current code adds VAT on top and returns grand total 1120.00 style behavior.

- [ ] **Step 3: Implement tax-mode-aware payload and retry amount logic**

```php
use App\Support\Tax\VatInclusiveCalculator;

$pricingSnapshot = $this->calculateRepairPricingSnapshot($repair);
$taxMode = (string) data_get($pricingSnapshot['pricing_breakdown'] ?? [], 'tax_mode', 'legacy_additive');
$finalTotal = round(max(0.0, (float) $pricingSnapshot['final_total']), 2);

if ($taxMode === 'vat_inclusive') {
    $breakdown = VatInclusiveCalculator::extract($finalTotal, self::REPAIR_VAT_RATE_PERCENT);
    $vatAmount = $breakdown['vat'];
    $grandTotal = $breakdown['total'];
} else {
    $vatAmount = round($finalTotal * (self::REPAIR_VAT_RATE_PERCENT / 100), 2);
    $grandTotal = round($finalTotal + $vatAmount, 2);
}

// Retry payment amount should be inclusive due amount, not +12% again.
$dueTotal = $policy === 'full_upfront'
    ? round($chargeSubtotal, 2)
    : round(max(1.0, $chargeSubtotal / 2), 2);
$dueBreakdown = VatInclusiveCalculator::extract($dueTotal, self::REPAIR_VAT_RATE_PERCENT);
$amount = $dueBreakdown['total'];
$vatAmount = $dueBreakdown['vat'];
$dueNet = $dueBreakdown['net'];
```

- [ ] **Step 4: Run feature tests to verify pass**

Run: `php artisan test tests/Feature/RepairVatInclusivePayloadTest.php --filter=my_repairs_payload_uses_extracted_vat`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairRequestController.php tests/Feature/RepairVatInclusivePayloadTest.php
git commit -m "feat: make repair payload totals VAT-inclusive aware"
```

---

### Task 4: Add Frontend Shared Repair Pricing Helper

**Files:**
- Create: `resources/js/utils/repairPricing.ts`
- Create: `resources/js/utils/__tests__/repairPricing.test.ts`

- [ ] **Step 1: Write failing Vitest suite for inclusive and legacy modes**

```ts
import { describe, expect, it } from 'vitest';
import { buildRepairBreakdown } from '../repairPricing';

describe('buildRepairBreakdown', () => {
  it('extracts VAT from inclusive total', () => {
    const row = buildRepairBreakdown({ finalTotal: 500, vatRate: 12, taxMode: 'vat_inclusive' });

    expect(row.grandTotal).toBe(500);
    expect(row.vatAmount).toBe(53.57);
    expect(row.netSubtotal).toBe(446.43);
  });

  it('keeps legacy add-on math for legacy mode', () => {
    const row = buildRepairBreakdown({ finalTotal: 500, vatRate: 12, taxMode: 'legacy_additive' });

    expect(row.vatAmount).toBe(60);
    expect(row.grandTotal).toBe(560);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm vitest resources/js/utils/__tests__/repairPricing.test.ts --run`
Expected: FAIL with module not found for `repairPricing`.

- [ ] **Step 3: Implement helper**

```ts
export type RepairTaxMode = 'vat_inclusive' | 'legacy_additive';

type BuildRepairBreakdownInput = {
  finalTotal: number;
  vatRate?: number;
  taxMode?: RepairTaxMode;
};

const round2 = (value: number) => Math.round((Number.isFinite(value) ? value : 0) * 100) / 100;

export const buildRepairBreakdown = ({
  finalTotal,
  vatRate = 12,
  taxMode = 'legacy_additive',
}: BuildRepairBreakdownInput) => {
  const safeFinal = round2(Math.max(finalTotal, 0));
  const safeRate = Math.max(vatRate, 0);

  if (taxMode === 'vat_inclusive' && safeRate > 0) {
    const vatAmount = round2(safeFinal * (safeRate / (100 + safeRate)));
    const netSubtotal = round2(safeFinal - vatAmount);

    return {
      taxMode,
      vatRate: safeRate,
      netSubtotal,
      vatAmount,
      grandTotal: safeFinal,
    };
  }

  const vatAmount = round2(safeFinal * (safeRate / 100));
  return {
    taxMode,
    vatRate: safeRate,
    netSubtotal: safeFinal,
    vatAmount,
    grandTotal: round2(safeFinal + vatAmount),
  };
};
```

- [ ] **Step 4: Re-run Vitest suite**

Run: `pnpm vitest resources/js/utils/__tests__/repairPricing.test.ts --run`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/utils/repairPricing.ts resources/js/utils/__tests__/repairPricing.test.ts
git commit -m "feat: add shared repair pricing breakdown helper"
```

---

### Task 5: Show Breakdown on Job Order Repair Pages (Required UI Update)

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx`
- Test: `resources/js/utils/__tests__/repairPricing.test.ts`

- [ ] **Step 1: Write failing helper test for breakdown row labels expected by job order pages**

```ts
it('returns values suitable for job-order breakdown rendering', () => {
  const row = buildRepairBreakdown({ finalTotal: 1000, vatRate: 12, taxMode: 'vat_inclusive' });

  expect(row.netSubtotal).toBe(892.86);
  expect(row.vatAmount).toBe(107.14);
  expect(row.grandTotal).toBe(1000);
});
```

- [ ] **Step 2: Run Vitest to verify failure before UI integration**

Run: `pnpm vitest resources/js/utils/__tests__/repairPricing.test.ts --run`
Expected: FAIL if helper output or rounding is not aligned with display expectations.

- [ ] **Step 3: Integrate helper into repairer and shop-owner mapping + rendering**

```tsx
import { buildRepairBreakdown } from '@/utils/repairPricing';

const taxMode = (repair.pricing_breakdown?.tax_mode ?? 'legacy_additive') as 'vat_inclusive' | 'legacy_additive';
const breakdown = buildRepairBreakdown({
  finalTotal: subtotalAmount,
  vatRate,
  taxMode,
});

// Persist formatted values into view model
vatAmount: formatPesoAmount(breakdown.vatAmount),
grandTotal: formatPesoAmount(breakdown.grandTotal),
finalPrice: formatPesoAmount(breakdown.netSubtotal),

// In details panel, always render explicit breakdown lines
<span className="text-sm text-gray-700 dark:text-gray-300">Subtotal</span>
<span className="text-sm font-semibold text-gray-900 dark:text-white">{viewOrder.finalPrice || '₱0.00'}</span>
<span className="text-sm text-gray-600 dark:text-gray-400">VAT ({viewOrder.vatRate ?? 12}%)</span>
<span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.vatAmount || '₱0.00'}</span>
<span className="text-sm text-gray-700 dark:text-gray-300">Grand Total</span>
<span className="text-sm font-semibold text-gray-900 dark:text-white">{viewOrder.grandTotal || viewOrder.total}</span>
```

- [ ] **Step 4: Re-run helper tests and frontend build**

Run: `pnpm vitest resources/js/utils/__tests__/repairPricing.test.ts --run`
Expected: PASS.

Run: `pnpm run build`
Expected: PASS, no TypeScript errors in either JobOrdersRepair page.

- [ ] **Step 5: Commit**

```bash
git add "resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx" "resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx" resources/js/utils/__tests__/repairPricing.test.ts
git commit -m "feat: render explicit VAT breakdown in repair job orders"
```

---

### Task 6: Align POS and Customer Repair Views to Inclusive Totals

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts`

- [ ] **Step 1: Add failing test that non-cash/cash validation remains unchanged after pricing refactor**

```ts
it('keeps payment validation behavior stable after VAT refactor', () => {
  const canPay = computeCanPay({
    itemsCount: 1,
    customerName: 'Ana',
    customerPhone: '09171234567',
    paymentMethod: 'cash',
    cashReceivedInput: '500',
    hasInsufficientCash: false,
    proofReference: '',
  });

  expect(canPay).toBe(true);
});
```

- [ ] **Step 2: Run existing POS validation test file**

Run: `pnpm vitest resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts --run`
Expected: PASS baseline before edits.

- [ ] **Step 3: Update pages to consume shared breakdown and stop adding VAT on top of inclusive totals**

```tsx
import { buildRepairBreakdown } from '@/utils/repairPricing';

const breakdown = buildRepairBreakdown({
  finalTotal: chargeableSubtotal,
  vatRate: 12,
  taxMode: 'vat_inclusive',
});

const taxableBase = breakdown.netSubtotal;
const vatAmount = breakdown.vatAmount;
const totalDue = breakdown.grandTotal;
```

```tsx
const subtotalAmount = Number(paymentData?.total_amount ?? order.final_total ?? 0);
const breakdown = buildRepairBreakdown({
  finalTotal: subtotalAmount,
  vatRate: Number(paymentData?.vat_rate ?? 12),
  taxMode: (paymentData?.tax_mode ?? 'vat_inclusive') as 'vat_inclusive' | 'legacy_additive',
});

const vatAmount = breakdown.vatAmount;
const totalAmount = breakdown.grandTotal;
```

- [ ] **Step 4: Run frontend tests and build**

Run: `pnpm vitest resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts --run`
Expected: PASS.

Run: `pnpm run build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add "resources/js/Pages/ERP/repairer/POS.tsx" "resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx" "resources/js/Pages/UserSide/Repairs/RepairProcess.tsx" "resources/js/Pages/UserSide/Repairs/myRepairs.tsx" resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
git commit -m "feat: align repair POS and customer pages with VAT-inclusive totals"
```

---

### Task 7: End-to-End Regression Gate for Repairs Phase 1

**Files:**
- Modify: `docs/plans/2026-04-05-vat-inclusive-repair-pricing-phase1-design.md`

- [ ] **Step 1: Add rollout verification notes to design doc**

```md
## Phase 1 Verification Log
- [ ] Repair POS checkout stores `metadata.tax_mode = vat_inclusive`
- [ ] Deposit amount equals 50% of displayed inclusive final price
- [ ] Job order details show subtotal, VAT, and grand total breakdown
- [ ] Customer my-repairs grand total does not add VAT twice
```

- [ ] **Step 2: Run targeted backend suite**

Run: `php artisan test --filter=RepairPosPaymentFlowTest`
Expected: PASS.

Run: `php artisan test tests/Feature/RepairVatInclusivePayloadTest.php`
Expected: PASS.

- [ ] **Step 3: Run targeted frontend suite**

Run: `pnpm vitest resources/js/utils/__tests__/repairPricing.test.ts --run`
Expected: PASS.

Run: `pnpm run build`
Expected: PASS.

- [ ] **Step 4: Final commit**

```bash
git add docs/plans/2026-04-05-vat-inclusive-repair-pricing-phase1-design.md
git commit -m "docs: add VAT-inclusive repairs phase1 verification log"
```

---

## Self-Review

### 1. Spec coverage check
- VAT-inclusive for new repair transactions: covered in Tasks 1-3.
- Preserve historical records: covered via tax-mode branching in Tasks 3-4.
- Deposit 50/50 on inclusive displayed totals: covered in Tasks 2-3.
- Extract VAT using 12/112 formula: covered in Tasks 1-6.
- Job order repair breakdown visible: covered in Task 5.
- Repairs-first phased rollout: all tasks scoped to repair module files.

### 2. Placeholder scan
- No TBD/TODO placeholders in tasks.
- Each code step contains concrete code snippets.
- Each run step contains executable commands and expected outcomes.

### 3. Type/signature consistency
- Backend helper method signature is `VatInclusiveCalculator::extract(float, float)` in tasks using it.
- Frontend helper is `buildRepairBreakdown({ finalTotal, vatRate, taxMode })` across Task 4-6.
- Tax mode constants are consistent: `vat_inclusive` and `legacy_additive`.
