# Customer Delivery Receipt and Dispute Implementation Plan

> For agentic workers: follow this plan to implement the approved customer receipt, delivery dispute, and return-inspection changes.

## Goal

Allow customers to confirm receipt after a shop-owned rider submits delivery proof, even while dispatcher approval is pending, without changing the authoritative order delivery status. Complete the existing customer dispute/reporting flow and make staff return inspection mandatory for individual/non-company refunds, with inventory disposition applied before a return is received.

## Architecture

- Keep the existing `POST /orders/confirm-delivery` endpoint, but move receipt transition rules into a focused service so third-party and shop-owned behavior remain explicit and idempotent.
- Use the current shipment leg status (`awaiting_proof_approval`) as the server-side signal for early shop-owned receipt availability. Expose a boolean action flag in customer order payloads; the frontend must not infer eligibility from unrelated `pickup_enabled` state.
- Keep `ShipmentController@approveProof` and `ShipmentLegService` as the authoritative shop-owned delivery path, changing the retail order completion state from `completed` to `delivered` without overwriting customer receipt acknowledgement.
- Add order receipt fields and `DeliveryDispute` persistence/services/routes on the existing customer and logistics surfaces, not a new Action Center.
- Harden `OrderRefundService::confirmReturnReceived` so both company and individual/non-company returns require an exact line inspection payload and use `RefundInventoryDispositionService` before `return_status = received`.
- Reuse existing staff inspection controls and refund approval/payout gates; only adjust their validation and data handoff where the current individual path bypasses inspection or inventory application.

## Tech Stack

Laravel 12, PHP 8.2, Eloquent, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, pnpm.

---

## Task 1: Add regression tests before implementation

**Files:**

- `tests/Feature/...` for customer receipt and dispute behavior (use the repository's existing order/logistics test conventions).
- `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php`.
- `tests/Feature/OrderRefundReturnInspectionTest.php` or a focused sibling test.

**Work:**

1. Add a failing test for a shop-owned order whose shipment leg is `awaiting_proof_approval`: customer confirmation sets receipt to `confirmed` and leaves the order status unchanged.
2. Add a failing test that dispatcher proof approval changes the shop-owned order to `delivered` while preserving an earlier receipt confirmation.
3. Preserve coverage for third-party `shipped -> delivered` confirmation and idempotency.
4. Add dispute/report tests for ownership, duplicate active reports, and no order-status rollback.
5. Replace the existing individual/non-company expectation that allows a return with no dispositions. Add failing coverage requiring every expected line, validating quantity/item/variant, and applying both `resellable` restock and `damaged` write-off inventory actions.
6. Keep the payout-gate assertion that `executeApprovedRefund` cannot proceed before an inspected return is `received`.

**Verification:** Run the narrowest PHPUnit filters available. If dependencies are unavailable, still keep the tests syntactically valid and record the environment limitation.

## Task 2: Implement receipt state and customer order payload

**Files:**

- New migration under `database/migrations/`.
- `app/Models/Order.php`.
- New `app/Services/OrderReceiptService.php` (or the repository's established equivalent).
- `app/Http/Controllers/UserSide/OrderController.php`.
- Existing order-resource/helper classes if the payload is assembled there.

**Work:**

1. Add receipt fields, casts, indexes, and the approved backfill for existing delivered/completed orders.
2. Implement transaction/row-lock/idempotency rules for third-party confirmation, shop-owned early confirmation, and already-delivered shop-owned confirmation.
3. Resolve the current shipment leg for the authenticated order and allow early confirmation only for a shop-owned `awaiting_proof_approval` leg.
4. Return server-authoritative receipt state, timestamps, active dispute summary, reporting eligibility, and `can_confirm_receipt`/equivalent action metadata.
5. Preserve ownership checks, active-dispute blocking, existing COD/payment side effects, and legacy third-party behavior.

**Verification:** Run receipt-focused tests and `php -l` for changed PHP files.

## Task 3: Align shop-owned completion with dispatcher authority

**Files:**

- `app/Services/Logistics/ShipmentLegService.php`.
- Existing shipment approval/rejection tests and any order-status assertions.
- Existing notification/event helpers when required by current conventions.

**Work:**

1. Change the shop-owned retail completion update from `completed` to `delivered`.
2. Do not set receipt to confirmed during dispatcher approval; preserve an earlier customer acknowledgement.
3. Ensure proof rejection does not set the order delivered and does not silently erase an early receipt audit timestamp.
4. Keep third-party, repair, and non-retail logistics completion paths unchanged.

**Verification:** Run shipment approval/rejection tests and inspect all changed status branches for scope leaks.

## Task 4: Implement customer delivery disputes on existing logistics surfaces

**Files:**

- New migration/model/service for `DeliveryDispute`.
- `app/Http/Controllers/UserSide/OrderController.php` or a focused customer dispute controller.
- Existing logistics controller/routes/API resources.
- Existing Logistics Shipments page and its data/query helpers.
- Related customer/staff payload types and tests.

**Work:**

1. Create the dispute record with tenant-safe relationships, active-dispute locking, reason validation, and reporting-window enforcement.
2. Add customer `Report Order` handling that sets receipt to `disputed` without changing the order status.
3. Add dispatcher investigate/resolve actions on the existing Logistics Shipments route surface with shop authorization and idempotent transitions.
4. Link `refund_required`/`return_required` to the existing refund request workflow without bypassing approvals, customer return, inspection, or finance payout.
5. Keep `replacement_required` as a recorded/manual resolution in this scope.
6. Include dispute summaries and filters in Logistics Shipments and read-only awareness fields in Staff Job Orders.

**Verification:** Run dispute feature tests, authorization tests, and `git diff --check`.

## Task 5: Fix individual/non-company return inspection

**Files:**

- `app/Services/OrderRefundService.php`.
- `app/Http/Controllers/Api/StaffOrderController.php`.
- `app/Services/RefundInventoryDispositionService.php` only if a narrowly scoped reuse fix is required.
- `resources/js/Pages/ERP/STAFF/JobOrders.tsx` and related refund types if payload/validation changes require it.

**Work:**

1. Make `confirmReturnReceived` require a complete disposition for every expected refund line for individual/non-company orders, matching the company validation contract.
2. Reject missing, partial, duplicate, mismatched item/variant, invalid disposition, and over-quantity submissions before changing return state.
3. Apply `RefundInventoryDispositionService` for both `resellable` and `damaged` outcomes, with the existing idempotency guard.
4. Remove the current error-swallowing behavior that can mark a return received after persistence/inventory failure.
5. Preserve failed-delivery handling and existing company behavior while sharing only the common validation/action logic needed to avoid divergence.
6. Keep staff UI prompts aligned with the backend contract and make errors visible rather than allowing a no-disposition confirmation.

**Verification:** Run focused refund inspection and payout-gate tests, then PHP syntax checks.

## Task 6: Update customer receipt/report controls

**Files:**

- `resources/js/Pages/UserSide/Orders/MyOrders.tsx`.
- Shared frontend order/dispute types/components if present.

**Work:**

1. Replace the current `pickup_enabled`-only receipt gate with the server-authoritative action flag while preserving legacy third-party behavior.
2. Show `Order Received` during shop-owned `awaiting_proof_approval` and after delivered/pending receipt.
3. Explain that early acknowledgement does not replace dispatcher approval for official delivery status.
4. Add receipt-confirmed, dispute, and report-order states without disabling the allowed post-receipt reporting window.
5. Keep the interaction idempotent and refresh/local-update state from the server response.

**Verification:** Run frontend tests if configured; otherwise perform a production build if dependencies are available and record unavailable tooling accurately.

## Task 7: Review and completion gates

1. Perform a sequential standards review against Laravel/React repository conventions.
2. Perform a sequential spec review against the approved acceptance criteria.
3. Apply the required simplification, clean TypeScript, performance, security, and assumptions checks to the changed areas.
4. Audit reuse, dead code, authorization, tenant boundaries, transaction boundaries, and status transitions.
5. Run:

   ```text
   git diff --check
   composer test
   pnpm run test:frontend
   pnpm run build
   ```

6. Report exact pass/fail/blocked results. Do not claim frontend or PHPUnit success when the worktree lacks the required dependencies/tooling.
