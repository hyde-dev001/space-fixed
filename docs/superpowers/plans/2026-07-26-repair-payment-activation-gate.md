# Repair Payment Activation Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require explicit repairer payment activation after repair acceptance before a customer can pay for shop-owned pickup.

**Architecture:** Keep `payment_enabled` as the single server-owned gate. Stop enabling it when a shop-pickup request is created, reuse the existing activation endpoint after acceptance, and hide the customer payment action until that flag becomes true.

**Tech Stack:** Laravel/PHP, React/TypeScript, PHPUnit, Vitest

---

### Task 1: Disable premature shop-pickup payment

**Files:**
- Modify: `tests/Feature/Repair/RepairAddressSnapshotTest.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php:238`

- [ ] **Step 1: Write the failing request-creation and activation-gate assertions**

In the existing shop-owned intake test, assert:

```php
$this->assertFalse((bool) $repair->payment_enabled);
$this->assertNull($repair->payment_enabled_at);

$this->actingAs($shop, 'shop_owner')
    ->postJson("/api/shop-owner/repairs/{$repair->id}/activate-payment")
    ->assertStatus(400);
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php artisan test tests/Feature/Repair/RepairAddressSnapshotTest.php
```

Expected: FAIL because a newly created `shop_pickup` request currently has payment enabled.

- [ ] **Step 3: Apply the minimal server fix**

Change request creation to preserve automatic payment only for walk-in intake:

```php
$autoEnableOnlinePayment = $intakeDeliveryMethod === 'walk_in';
```

- [ ] **Step 4: Run the focused backend test**

Run:

```bash
php artisan test tests/Feature/Repair/RepairAddressSnapshotTest.php
```

Expected: PASS.

### Task 2: Show customer payment only after activation

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx:4119-4173`

- [ ] **Step 1: Add a failing customer regression test**

Render an accepted shop-pickup repair with `payment_enabled: false` and assert:

```tsx
expect(screen.queryByRole("button", { name: "PAY NOW" })).not.toBeInTheDocument();
```

Keep the existing enabled case proving that the same accepted repair shows **PAY NOW** after activation.

- [ ] **Step 2: Run the focused frontend test and verify it fails**

Run:

```bash
npm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

Expected: FAIL because the disabled **PAY NOW** button is still rendered.

- [ ] **Step 3: Apply the minimal visibility gate**

Require both the online intake flow and the server flag in the accepted and pending action sections:

```tsx
{isOnlineIntakeFlow(order) && order.payment_enabled && (
```

- [ ] **Step 4: Run the focused frontend test**

Run the command from Step 2.

Expected: PASS.

### Task 3: Preserve repairer activation access

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx:3806`
- Modify: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`

- [ ] **Step 1: Verify the accepted repair detail regression**

Run:

```bash
npm run test:frontend -- resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
```

Expected: PASS with the existing regression proving that an accepted unpaid repair exposes **Activate Payment**.

### Task 4: Full verification and production assets

**Files:**
- Refresh: `public/build/**`

- [ ] **Step 1: Run related backend payment, logistics, POS, and warranty tests**

```bash
php artisan test tests/Feature/Repair/RepairAddressSnapshotTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php
```

- [ ] **Step 2: Run the complete frontend suite**

```bash
npm run test:frontend
```

- [ ] **Step 3: Build production assets**

```bash
npm run build
```

- [ ] **Step 4: Check the final diff**

```bash
git diff --check
git status --short
```
