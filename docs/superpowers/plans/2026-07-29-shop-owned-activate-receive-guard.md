# Shop-Owned Activate Receive Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show `Activate Receive` for shipped third-party orders, never for shop-owned logistics orders.

**Architecture:** Reuse the existing mapped `carrierCompany` value and `SHOP_OWNED_LOGISTICS` constant in the order-details modal guard. Keep the backend receive endpoint and third-party flow unchanged.

**Tech Stack:** React, TypeScript, Vitest, Testing Library, Vite

---

### Task 1: Guard Activate Receive by carrier

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx:2625`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- Build: `public/build`

- [ ] **Step 1: Write the failing component test**

Add one test that renders two shipped orders: one with
`carrier_company: 'Shop-owned logistics'` and one with
`carrier_company: 'J&T'`. Open each order-details modal and assert that
`Activate Receive` is absent for the shop-owned order and present for the
third-party order.

- [ ] **Step 2: Run the focused test to verify RED**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
```

Expected: FAIL because the shop-owned shipped modal still renders
`Activate Receive`.

- [ ] **Step 3: Add the minimal production guard**

Change the existing condition to:

```tsx
viewOrder.status === "shipped"
  && viewOrder.carrierCompany !== SHOP_OWNED_LOGISTICS
  && !canConfirmReturnReceived(viewOrder)
```

- [ ] **Step 4: Run focused and related tests**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx
```

Expected: 2 files and all tests PASS.

- [ ] **Step 5: Run the related backend logistics tests**

Run:

```powershell
php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php
```

Expected: all shipment-leg service tests PASS.

- [ ] **Step 6: Build production assets**

Run:

```powershell
npm run build
```

Expected: Vite exits successfully and refreshes the matching manifest and
hashed assets in `public/build`.

- [ ] **Step 7: Inspect and commit**

Run `git diff --check`, confirm only the spec/plan, focused source/test, and
generated build changes are present, then stage and commit:

```powershell
git add -- docs/superpowers/plans/2026-07-29-shop-owned-activate-receive-guard.md resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts public/build
git commit -m "fix: hide receive activation for shop-owned orders"
```
