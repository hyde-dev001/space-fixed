# Cross-Module Repair, POS, Inventory, Finance, Notification, and Invoice Fixes Implementation Plan

> **For agentic workers:** Execute this plan sequentially in the current worktree. Keep each red-green check focused and do not add unrelated refactors.

**Goal:** Make repair notifications, delivery labels, Repair POS, Retail POS inventory/Finance posting, and invoice due dates resolve from their canonical server-side sources without partial transactions.

**Architecture:** Keep the existing Repair POS, Retail POS, `FinanceSummaryService`, notification, and invoice APIs. Remove the incorrect Staff recipient path, use the existing shop-scoped shipment number in rider presentation, make manual Repair POS creation atomic with settlement, share the online linked-inventory deduction logic with Retail POS, exclude POS-backed orders from generic Finance order aggregation, and derive invoice due dates from a payment condition stored in invoice metadata.

**Tech Stack:** Laravel 12/PHP 8.2, Eloquent transactions and row locks, Inertia React 18/TypeScript, Vitest, PHPUnit/Pest repository conventions, Vite/pnpm.

---

### Task 1: Repair notification recipient boundary

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Test: existing repair notification feature test or `tests/Feature/Repair/RepairNotificationTest.php`

- [ ] Write a failing test that creates a repair, an eligible active Repairer, and a generic active Staff user in the same shop; assert only the repair workflow recipient receives the new-repair notification and the action URL is the Repairer-authorized route.
- [ ] Run the focused Laravel test and confirm it fails because the generic Staff notification is currently created.
- [ ] Remove the explicit `notifyAllStaffNewRepair` call from repair creation. Preserve existing Repairer auto-assignment and shop-owner behavior.
- [ ] Run the focused test and nearby repair notification tests.

### Task 2: Shop-scoped delivery display

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [ ] Add a failing presentation test with a single work item whose leg ID differs from `shipment.shipment_number`; assert the UI uses the shipment number.
- [ ] Run the focused Vitest test and confirm the current label uses the leg ID.
- [ ] Type the nested `shipment_number` already returned by the rider payload and use it for single-delivery titles and stop labels, retaining the existing fallback only for legacy payloads.
- [ ] Run the focused Logistics tests.

### Task 3: Atomic Repair POS checkout and cash tender

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Services/RepairPosReceiptService.php` if receipt totals need the canonical tender/change values
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx`
- Test: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] Add failing tests for cash tendered above due, failed manual checkout leaving zero repair/payment/receipt/notification records, and repeated idempotent manual checkout creating one repair.
- [ ] Run those tests and confirm the current exact-payment validation and pre-service manual repair creation fail them.
- [ ] Validate `cash_received` at the API boundary; keep applied payment lines equal to the due amount, accept cash tendered greater than or equal to due, and reject underpayment/non-cash mismatch according to current rules.
- [ ] Lock the shop scope and wrap manual repair creation plus `RepairPosPaymentService::checkout` in one transaction; perform the same idempotency lookup before creating a manual repair.
- [ ] Store canonical cash tender/change in transaction metadata and return it through the existing receipt payload. Update the POS client to send tendered cash and render the response values.
- [ ] Run all Repair POS payment tests and verify rollback/idempotency behavior.

### Task 4: Canonical linked-inventory deduction for Retail POS

**Files:**
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php` or extract only its existing deduction helper
- Modify: `app/Services/RetailPosPaymentService.php`
- Test: `tests/Feature/RetailPosPaymentFlowTest.php` and the closest existing online inventory test

- [ ] Add failing tests for a linked inventory item/size/color variant: online quantity one followed by Retail POS quantity two must update canonical available quantity and create a POS-referenced stock-out movement; insufficient stock must roll back the POS sale.
- [ ] Run the focused tests and confirm Retail POS currently changes only `Product`/`ProductVariant`.
- [ ] Reuse the existing online deduction algorithm through the smallest shared helper; lock the canonical rows, deduct the exact option, write `StockMovement`, and keep product fallback for unlinked items.
- [ ] Call the helper from the Retail POS transaction with the created order as reference and avoid a second product/variant decrement for linked inventory.
- [ ] Run Retail POS and online checkout inventory tests.

### Task 5: Finance visibility without double posting

**Files:**
- Modify: `app/Services/Finance/FinanceSummaryService.php`
- Test: `tests/Feature/Finance/FinanceSummaryTest.php` and/or `tests/Feature/RetailPosPaymentFlowTest.php`

- [ ] Add a failing regression that completes a Retail POS sale and asserts Finance summary includes its revenue once, not once as an order plus once as a POS transaction.
- [ ] Run the focused test and confirm the current generic order query also sees the POS-created order.
- [ ] Exclude orders backed by a retail `pos_transactions` row from `addOrders`; leave the existing POS aggregation unchanged.
- [ ] Run Finance summary and Retail POS tests, including refund and online-order regressions.

### Task 6: Payment-condition-derived invoice due dates

**Files:**
- Modify: `app/Http/Controllers/Api/Finance/InvoiceController.php`
- Modify: `resources/js/Pages/ERP/Finance/createInvoice.tsx`
- Test: focused Finance invoice feature test and the closest existing frontend invoice test

- [ ] Add failing API tests for Net 7/15/30, same-day Due on receipt, and a tampered submitted due date; add a frontend regression for derived/read-only due date.
- [ ] Run the tests and confirm the API ignores no condition today and the UI does not send one.
- [ ] Add the selected condition to existing `meta`, derive date-only due dates with the application/shop timezone, and recalculate on create/update whenever a condition is supplied.
- [ ] Recalculate the UI date when issue date or condition changes, make the field read-only, and send `payment_condition` instead of trusting the displayed date.
- [ ] Run focused Finance tests and the invoice frontend test.

### Task 7: Full verification and handoff

**Files:**
- Modify only if verification exposes an in-scope defect.

- [ ] Run `git diff --check`.
- [ ] Run focused Laravel and frontend tests, then `composer test` and `pnpm run test:frontend` where practical.
- [ ] Run `pnpm run build` from the rebased worktree to regenerate a fresh `public/build`; do not include `storage/framework/cache/`.
- [ ] Inspect `git status`, changed-file list, and diff for scope, dead imports, stale references, and generated artifacts.
- [ ] Record exact test/build results and any existing duplicate repair or unposted POS data that needs reconciliation; do not perform a data backfill without explicit approval.

