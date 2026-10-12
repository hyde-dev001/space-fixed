# Company Repair Third-Party Payment and Handoff Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make company repair third-party payment, courier tracking, and employee handoff follow one server-enforced sequence without changing the repair schema.

**Architecture:** Extend the existing payment and delivery services at their current boundaries. `PaymentSettlementService` remains authoritative for payable phases, `RepairDeliveryService` remains authoritative for tracking and physical handoff, and `RepairPosPaymentService` canonicalizes repair customer identity before creating the existing POS ledger record. React pages only mirror those server decisions and explain the next action.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit feature tests, React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, pnpm.

---

### Task 1: Add failing regression coverage for the third-party return sequence

**Files:**
- Modify: `tests/Feature/Repair/RepairReturnHandoffTest.php`
- Modify: `tests/Feature/Repair/RepairLogisticsPaymentTest.php` or the nearest existing payment-boundary test after fixture review

- [x] **Step 1: Write failing tests**

  Cover a company repair in `ready_for_pickup` with `customer_pickup`, a completed balance, and a non-null `return_logistics_locked_at`. Assert that tracking can still be saved before handoff, that `returnHandoff` reports tracking as the missing prerequisite when absent, and that the assigned repairer can activate handoff after tracking is saved. Assert tracking changes are rejected after `pickup_enabled`.

  Add an initial `customer_delivery` payment test that rejects missing intake carrier/tracking and succeeds after those details are saved. Preserve explicit coverage for walk-in/shop-owned/warranty paths if the selected fixture needs it.

- [x] **Step 2: Run only the new/affected tests and confirm the current failure**

  Run:

  ```powershell
  php artisan test tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php
  ```

  Expected: the new expectations fail because the return plan lock blocks tracking/handoff and the payment boundary does not yet enforce intake tracking.

  Observed: the new tests failed for those exact pre-fix reasons before the production changes were applied.

### Task 2: Separate return plan locking from tracking and physical handoff

**Files:**
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Test: `tests/Feature/Repair/RepairReturnHandoffTest.php`

- [x] **Step 1: Add the smallest tracking-completeness check in the existing delivery service**

  Reuse the stored address snapshot and require non-empty `external_tracking.carrier` and `external_tracking.tracking_number` for `customer_pickup` return handoff.

- [x] **Step 2: Update the authoritative return handoff payload and mutation**

  Let paid `customer_pickup` plans remain releasable only after tracking is complete. Do not interpret `return_logistics_locked_at` as physical receipt confirmation. Keep `pickup_enabled` as the physical handoff/idempotency gate, preserve the existing assigned-repairer/company and dispatcher/shop-delivery actor rules, and return a clear tracking error when absent.

- [x] **Step 3: Allow customer return tracking after balance payment but before handoff**

  Adjust the existing `updateExternalTracking` lock decision for normal `customer_pickup` returns so the payment plan lock does not prevent tracking updates until `pickup_enabled` is true. Keep intake locking and post-handoff immutability intact, including warranty exceptions.

- [x] **Step 4: Run the focused return tests**

  ```powershell
  php artisan test tests/Feature/Repair/RepairReturnHandoffTest.php
  ```

  Expected: PASS, including company repairer authorization, customer confirmation, no shipment for third-party return, and no mutation on unauthorized actors.

### Task 3: Enforce the customer-arranged intake tracking payment gate

**Files:**
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php` only if a response mapping is needed
- Test: `tests/Feature/Repair/RepairLogisticsPaymentTest.php` and/or the selected focused payment test

- [x] **Step 1: Add the failing payment-boundary assertion**

  Verify `repairPaymentBreakdown`/customer retry-session creation rejects an initial payable `customer_delivery` repair without stored intake tracking, and accepts the same repair after carrier and tracking number are stored.

- [x] **Step 2: Implement the guard at the shared breakdown boundary**

  Gate only the initial payable phase for canonical `customer_delivery` intake. Use the existing snapshot, throw a validation error with customer-readable guidance, and do not gate final balance payments or existing warranty/recovery exception paths.

- [x] **Step 3: Verify both retry-session and POS callers observe the same guard**

  ```powershell
  php artisan test tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairPaymentSummaryTest.php
  ```

  Expected: PASS with no PayMongo session or POS settlement created on a blocked request.

### Task 4: Canonicalize repair customer identity in POS checkout

**Files:**
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php` if its legacy POS validation is still reachable
- Test: `tests/Feature/Repair/RepairPosPaymentFlowTest.php` or the existing POS repair feature test

- [x] **Step 1: Add failing guest and registered repair checkout tests**

  Assert a guest repair succeeds with a null customer ID and canonical repair snapshot, a registered repair succeeds with its linked user, and a mismatched existing user ID is rejected without a transaction.

- [x] **Step 2: Implement locked-repair identity validation and normalization**

  After locking the repair, derive the account-backed/guest mode from `RepairRequest.user_id`. Require a matching registered ID for account-backed repairs; require null customer ID for guests; use the repair's name/phone/email snapshot for guest POS transaction fields. Keep manual walk-in POS creation compatible.

- [x] **Step 3: Remove the frontend `Number(null) => 0` payload path**

  Normalize optional customer IDs in the active cashier and repairer POS queue mappings, and update the legacy shop-owner repair POS mapping if it shares the same path. Preserve the server-provided `collectible_amount` and `due_type`.

- [x] **Step 4: Run focused POS tests**

  ```powershell
  php artisan test tests/Feature/Repair/RepairPosPaymentFlowTest.php
  pnpm exec vitest run resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx
  ```

  Expected: PASS for guest, account-backed, exact due amount, idempotency, and invalid identity cases.

### Task 5: Update customer guidance and frontend regression coverage

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx`
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx` only if the shared bug is present
- Test: existing customer/POS frontend tests, adding a small focused test where practical

- [x] **Step 1: Change the return tracking lock and copy**

  Show editable return tracking after payment and before handoff. Tell the customer to pay the remaining balance first, then enter carrier/tracking details, then wait for shop handoff. Show the existing read-only tracking view after handoff.

- [x] **Step 2: Surface server validation in the existing payment error path**

  Ensure the customer sees the courier-tracking requirement instead of a generic payment-session failure when the server blocks an initial payment.

- [x] **Step 3: Run the focused frontend test/build checks**

  ```powershell
  pnpm run test:frontend
  pnpm run build
  ```

  Result: the focused Laravel suite passed with 84 warning-marked tests and 579 assertions, the full frontend suite reached 897/898 tests, and the production build passed. The repo-wide `composer test` attempt exceeded Composer's 300-second process timeout, so it is not reported as passing.

  Result: focused repairer job-order tests (15/15) and the production build passed. The full suite finished at 897/898 tests; its one failure is the pre-existing navigation contract expecting a missing `>Repairs</h1>` string, and the change does not touch that heading. No lint/type-check claim is made because the repository has no committed frontend lint or TypeScript compiler script.

### Task 6: Review, clean up, and verify the complete change

**Files:**
- Review all changed files and `docs/ai-learning-log.md` only if a durable project lesson is identified.

- [x] **Step 1: Run the required quality checks**

  ```powershell
  git diff --check
  php artisan test tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/RepairPosManualQueueTest.php tests/Feature/Repair/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairPaymentSummaryTest.php
  pnpm run test:frontend
  pnpm run build
  ```

- [x] **Step 2: Perform sequential standards/spec/security/simplification review**

  Check Laravel authorization/tenant scope, payment idempotency and exact amounts, React state/accessible messaging, reuse of current services, and removal of only change-created dead code. Record `N/A` for unrelated review gates.

  Result: passed sequential review. The change reuses the existing settlement/delivery/POS services, preserves actor and tenant checks, keeps idempotency and exact amount validation, adds no bundle-splitting requirement, and introduces no changed-area dead code. Performance baseline: not measured.

- [x] **Step 3: Inspect the final diff and report exact evidence**

  Confirm no `.env`, `vendor`, `node_modules`, or generated build files were edited, and report the rebase, tests, build, and any unmeasured performance baseline honestly.

  Result: rebase completed onto `origin/solespace-b` before implementation; `git diff --check` and PHP syntax checks passed; generated `public/build` artifacts were removed/restored after verification; no `.env`, `vendor`, or `node_modules` changes are present. The full `composer test` attempt timed out at 300 seconds and is therefore not a passing result.
