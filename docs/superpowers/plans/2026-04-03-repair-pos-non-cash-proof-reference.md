# Repair POS Non-Cash Proof Reference Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable real-life in-store GCash/Card POS completion (without cash input), while requiring proof reference and enforcing it in both UI and backend across Shop Owner and ERP Repairer POS.

**Architecture:** Keep the existing repair POS checkout pipeline and tender mapping, but add method-aware validation at two layers: frontend `canPay` gating and backend request validation. Persist and display `provider_reference` in receipt snapshot/print output for non-cash tenders. Reuse one shared frontend helper for pay eligibility and formatting rules to avoid drift between two POS pages.

**Tech Stack:** Laravel 11 (PHP), PHPUnit feature tests, React + TypeScript (Inertia), Vitest.

---

## File Structure and Responsibilities

- Modify: `app/Http/Controllers/Api/RepairPosController.php`
  - Add conditional backend validation: non-cash (`paymongo_wallet`, `paymongo_card`) requires non-empty `provider_reference`.
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
  - Mirror same conditional validation for `checkoutViaPos` to keep policy consistent across repair POS entrypoints.
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`
  - Add/adjust feature tests that prove non-cash reference enforcement and cash compatibility.
- Create: `resources/js/Pages/Repairs/posPaymentValidation.ts`
  - Shared pure utility for method-aware pay eligibility and phone display fallback.
- Create: `resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts`
  - Unit tests for shared validation utility.
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
  - Add proof reference field/state, method-aware `canPay`, payload `provider_reference`, receipt print/history fields.
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
  - Apply the same changes as Shop Owner POS for behavior parity.

---

### Task 1: Lock Backend Policy with Failing Feature Tests

**Files:**
- Modify: `tests/Feature/RepairPosPaymentFlowTest.php`
- Test: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] **Step 1: Write the failing tests for non-cash proof reference enforcement**

```php
#[Test]
public function non_cash_checkout_requires_provider_reference(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $customer = \App\Models\User::factory()->create();
    $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $repair = \App\Models\RepairRequest::create([
        'request_id' => 'REP-TDD-006',
        'customer_name' => $customer->name,
        'email' => $customer->email,
        'phone' => '09170000023',
        'shoe_type' => 'Sneakers',
        'description' => 'Non-cash ref required test',
        'shop_owner_id' => $shopOwner->id,
        'user_id' => $customer->id,
        'images' => json_encode([]),
        'total' => 1000,
        'final_total' => 1000,
        'status' => 'pending',
        'payment_policy' => 'deposit_50',
        'payment_policy_snapshot' => 'deposit_50',
        'payment_status' => 'pending',
    ]);

    $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => $repair->id,
        'due_type' => 'deposit',
        'customer_type' => 'registered',
        'customer_id' => $customer->id,
        'payment_lines' => [
            ['tender_type' => 'paymongo_wallet', 'amount' => 560, 'provider_reference' => null],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payment_lines.0.provider_reference']);
}

#[Test]
public function non_cash_checkout_accepts_provider_reference_and_persists_it(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $customer = \App\Models\User::factory()->create();
    $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $repair = \App\Models\RepairRequest::create([
        'request_id' => 'REP-TDD-007',
        'customer_name' => $customer->name,
        'email' => $customer->email,
        'phone' => '09170000024',
        'shoe_type' => 'Sneakers',
        'description' => 'Non-cash ref success test',
        'shop_owner_id' => $shopOwner->id,
        'user_id' => $customer->id,
        'images' => json_encode([]),
        'total' => 1000,
        'final_total' => 1000,
        'status' => 'pending',
        'payment_policy' => 'deposit_50',
        'payment_policy_snapshot' => 'deposit_50',
        'payment_status' => 'pending',
    ]);

    $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
        'repair_request_id' => $repair->id,
        'due_type' => 'deposit',
        'customer_type' => 'registered',
        'customer_id' => $customer->id,
        'payment_lines' => [
            ['tender_type' => 'paymongo_card', 'amount' => 560, 'provider_reference' => 'AUTH-REF-12345'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $this->assertDatabaseHas('pos_payment_lines', [
        'tender_type' => 'paymongo_card',
        'provider_reference' => 'AUTH-REF-12345',
        'status' => 'paid',
    ]);
}
```

- [ ] **Step 2: Run feature tests to verify failure first**

Run:
```bash
php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=provider_reference -v
```

Expected:
- FAIL on `non_cash_checkout_requires_provider_reference` (currently allows null reference).

- [ ] **Step 3: Commit failing test scaffold**

```bash
git add tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "test: add failing non-cash provider reference requirements"
```

---

### Task 2: Implement Backend Conditional Validation

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Test: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] **Step 1: Add conditional validation in repair POS checkout endpoint**

```php
$validated = $request->validate([
    'repair_request_id' => ['required', 'integer', 'exists:repair_requests,id'],
    'due_type' => ['required', 'string', 'in:deposit,balance,full'],
    'customer_type' => ['required', 'string', 'in:registered,walk_in'],
    'customer_id' => ['nullable', 'integer', 'exists:users,id'],
    'walk_in_name' => ['nullable', 'string', 'max:255'],
    'walk_in_phone' => ['nullable', 'string', 'max:30'],
    'walk_in_email' => ['nullable', 'email', 'max:255'],
    'payment_lines' => ['required', 'array', 'min:1'],
    'payment_lines.*.tender_type' => ['required', 'string', 'in:cash,paymongo_card,paymongo_wallet'],
    'payment_lines.*.amount' => ['required', 'numeric', 'min:0.01'],
    'payment_lines.*.provider_reference' => ['nullable', 'string', 'max:255'],
]);

foreach ($validated['payment_lines'] as $index => $line) {
    $isNonCash = in_array($line['tender_type'] ?? '', ['paymongo_card', 'paymongo_wallet'], true);
    $reference = trim((string) ($line['provider_reference'] ?? ''));

    if ($isNonCash && $reference === '') {
        throw \Illuminate\Validation\ValidationException::withMessages([
            "payment_lines.{$index}.provider_reference" => ['Reference is required for GCash/Card payments.'],
        ]);
    }
}
```

- [ ] **Step 2: Mirror the same rule in `checkoutViaPos` for consistency**

```php
foreach ($validated['payment_lines'] as $index => $line) {
    $isNonCash = in_array($line['tender_type'] ?? '', ['paymongo_card', 'paymongo_wallet'], true);
    $reference = trim((string) ($line['provider_reference'] ?? ''));

    if ($isNonCash && $reference === '') {
        throw \Illuminate\Validation\ValidationException::withMessages([
            "payment_lines.{$index}.provider_reference" => ['Reference is required for GCash/Card payments.'],
        ]);
    }
}
```

- [ ] **Step 3: Run provider reference tests and full payment flow tests**

Run:
```bash
php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=provider_reference -v
php artisan test tests/Feature/RepairPosPaymentFlowTest.php -v
```

Expected:
- All `provider_reference` tests PASS.
- Existing payment flow tests remain PASS.

- [ ] **Step 4: Commit backend validation changes**

```bash
git add app/Http/Controllers/Api/RepairPosController.php app/Http/Controllers/Api/RepairRequestController.php tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "feat: enforce non-cash provider reference in repair POS checkout"
```

---

### Task 3: Add Shared Frontend Validation Utility with Unit Tests

**Files:**
- Create: `resources/js/Pages/Repairs/posPaymentValidation.ts`
- Create: `resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts`
- Test: `resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts`

- [ ] **Step 1: Write failing Vitest cases for payment-method behavior**

```ts
import { describe, expect, it } from 'vitest';
import { computeCanPay, getPhoneDisplayForReceipt } from '../posPaymentValidation';

describe('computeCanPay', () => {
  it('requires proof reference for non-cash', () => {
    const canPay = computeCanPay({
      itemsCount: 1,
      customerName: 'Juan',
      customerPhone: '',
      paymentMethod: 'gcash',
      cashReceivedInput: '',
      hasInsufficientCash: false,
      proofReference: '',
    });

    expect(canPay).toBe(false);
  });

  it('allows non-cash without phone when proof reference exists', () => {
    const canPay = computeCanPay({
      itemsCount: 1,
      customerName: 'Juan',
      customerPhone: '',
      paymentMethod: 'card',
      cashReceivedInput: '',
      hasInsufficientCash: false,
      proofReference: 'AUTH-1',
    });

    expect(canPay).toBe(true);
  });

  it('still requires phone and cash input for cash payments', () => {
    const canPay = computeCanPay({
      itemsCount: 1,
      customerName: 'Juan',
      customerPhone: '',
      paymentMethod: 'cash',
      cashReceivedInput: '',
      hasInsufficientCash: false,
      proofReference: 'IGNORED',
    });

    expect(canPay).toBe(false);
  });
});

describe('getPhoneDisplayForReceipt', () => {
  it('returns N/A for non-cash when phone missing', () => {
    expect(getPhoneDisplayForReceipt('gcash', '')).toBe('N/A');
  });
});
```

- [ ] **Step 2: Run vitest to verify failures**

Run:
```bash
pnpm vitest run resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
```

Expected:
- FAIL because helper file does not exist yet.

- [ ] **Step 3: Add minimal helper implementation**

```ts
export type PosPaymentMethod = 'cash' | 'gcash' | 'card';

type CanPayInput = {
  itemsCount: number;
  customerName: string;
  customerPhone: string;
  paymentMethod: PosPaymentMethod;
  cashReceivedInput: string;
  hasInsufficientCash: boolean;
  proofReference: string;
};

export const isCashPhoneValid = (phone: string): boolean => phone.length === 11;

export const computeCanPay = (input: CanPayInput): boolean => {
  const hasItems = input.itemsCount > 0;
  const hasName = input.customerName.trim().length > 0;

  if (input.paymentMethod === 'cash') {
    return hasItems
      && hasName
      && isCashPhoneValid(input.customerPhone)
      && input.cashReceivedInput.trim().length > 0
      && !input.hasInsufficientCash;
  }

  return hasItems
    && hasName
    && input.proofReference.trim().length > 0;
};

export const getPhoneDisplayForReceipt = (method: PosPaymentMethod, phone: string): string => {
  if (method !== 'cash' && phone.trim().length === 0) return 'N/A';
  return phone.trim();
};
```

- [ ] **Step 4: Run helper tests and commit**

Run:
```bash
pnpm vitest run resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
```

Expected:
- PASS all tests.

Commit:
```bash
git add resources/js/Pages/Repairs/posPaymentValidation.ts resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
git commit -m "test: add shared POS payment validation helper and unit tests"
```

---

### Task 4: Update Shop Owner Repair POS UI and Receipt Behavior

**Files:**
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
- Test: `resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts`

- [ ] **Step 1: Add proof reference state and import shared helper**

```ts
import { computeCanPay, getPhoneDisplayForReceipt } from '../../Repairs/posPaymentValidation';

const [proofReference, setProofReference] = useState<string>('');

useEffect(() => {
  if (paymentMethod === 'cash' && proofReference.length > 0) {
    setProofReference('');
  }
}, [paymentMethod, proofReference]);
```

- [ ] **Step 2: Replace local `canPay` logic with helper-based method-specific logic**

```ts
const canPay = computeCanPay({
  itemsCount: items.length,
  customerName,
  customerPhone,
  paymentMethod,
  cashReceivedInput,
  hasInsufficientCash,
  proofReference,
});
```

- [ ] **Step 3: Send `provider_reference` in checkout payload for non-cash methods**

```ts
payment_lines: [
  {
    tender_type: mapTenderType(paymentMethod),
    amount: Number(totalDue.toFixed(2)),
    provider_reference: paymentMethod === 'cash' ? null : proofReference.trim(),
  },
],
```

- [ ] **Step 4: Update receipt snapshot and printable content**

```ts
type ReceiptSnapshot = {
  // existing fields...
  paymentReference?: string | null;
};

const snapshot: ReceiptSnapshot = {
  // existing fields...
  customerPhone: getPhoneDisplayForReceipt(paymentMethod, customerPhone),
  paymentReference: paymentMethod === 'cash' ? null : proofReference.trim(),
};

...(snapshot.paymentReference ? [`Reference: ${snapshot.paymentReference}`] : []),
```

- [ ] **Step 5: Render proof reference input in payment section**

```tsx
{paymentMethod !== 'cash' && (
  <div>
    <label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Proof Reference</label>
    <input
      title="GCash/Card reference"
      value={proofReference}
      onChange={(event) => setProofReference(event.target.value)}
      placeholder="Enter transaction/auth reference"
      className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
    />
  </div>
)}
```

- [ ] **Step 6: Run tests and commit Shop Owner POS changes**

Run:
```bash
pnpm vitest run resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
php artisan test tests/Feature/RepairPosPaymentFlowTest.php --filter=provider_reference -v
```

Expected:
- Vitest helper tests PASS.
- Feature tests PASS.

Commit:
```bash
git add resources/js/Pages/ShopOwner/Repairs/service\ management/POS.tsx
git commit -m "feat: require non-cash proof reference in shop owner repair POS"
```

---

### Task 5: Update ERP Repairer POS UI for Behavior Parity

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Test: `resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts`

- [ ] **Step 1: Apply the same state/validation/payload rules as Shop Owner POS**

```ts
const [proofReference, setProofReference] = useState<string>('');

const canPay = computeCanPay({
  itemsCount: items.length,
  customerName,
  customerPhone,
  paymentMethod,
  cashReceivedInput,
  hasInsufficientCash,
  proofReference,
});

provider_reference: paymentMethod === 'cash' ? null : proofReference.trim(),
```

- [ ] **Step 2: Apply receipt `Phone: N/A` + `Reference` line behavior**

```ts
customerPhone: getPhoneDisplayForReceipt(paymentMethod, customerPhone),
paymentReference: paymentMethod === 'cash' ? null : proofReference.trim(),
```

- [ ] **Step 3: Add proof reference input and auto-clear-on-cash switch**

```ts
useEffect(() => {
  if (paymentMethod === 'cash' && proofReference.length > 0) {
    setProofReference('');
  }
}, [paymentMethod, proofReference]);
```

- [ ] **Step 4: Run parity tests and commit**

Run:
```bash
pnpm vitest run resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
php artisan test tests/Feature/RepairPosPaymentFlowTest.php -v
```

Expected:
- Vitest PASS.
- Full repair POS payment flow PASS.

Commit:
```bash
git add resources/js/Pages/ERP/repairer/POS.tsx
git commit -m "feat: align ERP repair POS non-cash proof reference behavior"
```

---

### Task 6: End-to-End Verification and Documentation Notes

**Files:**
- Modify: `docs/superpowers/specs/2026-04-03-repair-pos-non-cash-proof-reference-design.md`

- [ ] **Step 1: Run final verification commands**

Run:
```bash
pnpm vitest run resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts
php artisan test tests/Feature/RepairPosPaymentFlowTest.php -v
```

Expected:
- All tests PASS.

- [ ] **Step 2: Manual QA checklist (both POS pages)**

```text
1) Cash selected, empty phone -> Pay disabled.
2) Cash selected, valid phone and enough cash -> Pay enabled.
3) GCash selected, empty proof reference -> Pay disabled.
4) GCash selected, proof reference filled, phone empty -> Pay enabled.
5) Switch GCash -> Cash -> proof reference auto-clears.
6) Complete non-cash checkout -> receipt shows Phone: N/A when no phone.
7) Complete non-cash checkout -> receipt shows Reference: <value>.
```

- [ ] **Step 3: Commit final verification/update notes**

```bash
git add docs/superpowers/specs/2026-04-03-repair-pos-non-cash-proof-reference-design.md
git commit -m "docs: finalize verification notes for repair POS non-cash proof reference"
```

---

## Self-Review Checklist (Completed)

- Spec coverage: all approved requirements are mapped to tasks (UI behavior split, proof reference requirement, backend enforcement, receipt output, both POS pages, tests).
- Placeholder scan: no TBD/TODO placeholders in task steps.
- Type consistency: `provider_reference`, tender mappings, and payment method names are consistent across tasks.
