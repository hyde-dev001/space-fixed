# Company Staff Retail Job Order, Dispute, Receipt, and Return Logistics Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make company Staff retail Job Orders authoritative and method-aware for disputes, POS receipts, return logistics, and inspection, including the approved third-party rule: saving valid Staff-entered third-party tracking immediately sets the return to `in_transit` without creating or consulting a Shop-owned shipment.

**Architecture:** Keep tenant scoping and existing order/refund/shipment history. Derive POS origin from the existing `PosTransaction` record instead of adding a schema field. Centralize return-method resolution at the refund/service boundary, make Shop-owned coverage and shipment checks backend-authoritative, and expose explicit method-aware payload fields to the Staff and customer React pages. Existing stale Shop-owned rows remain history only when the explicit method is third-party.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit, Inertia, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, pnpm.

---

## Task 1: Add failing regression coverage

**Files:**
- Modify `tests/Feature/OrderRefundReturnInspectionTest.php`
- Modify `tests/Feature/StaffOrderRefundPayloadTest.php`
- Modify or add the narrowest relevant POS/Staff feature test
- Modify `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx`
- Modify `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`

- [ ] Add a failing feature test for Staff-arranged third-party return tracking: valid required courier/tracking data stores customer/third-party return fields, sets `return_source=customer` and `return_status=in_transit`, creates no Shop-owned shipment, and permits Staff physical-receipt confirmation while inspection still requires that confirmation.
- [ ] Add a failing feature test that Shop-owned return arrangement is rejected when the order address is outside Shop rider coverage, while third-party arrangement remains available.
- [ ] Add a failing feature test that a stale Shop-owned shipment is ignored for an explicit third-party return in both Staff payloads and customer-facing tracking payloads.
- [ ] Add a failing feature test that a POS/walk-in retail order is explicitly identified as POS-origin and cannot enter the customer receipt acknowledgement flow.
- [ ] Add a failing feature assertion that dispute reason, notes/details, status, and reported date are present in the Staff-authorized payload.
- [ ] Add frontend regression coverage for POS receipt/receive suppression, method-aware return inspection eligibility, and the coverage-disabled Shop-owned option.
- [ ] Run only the focused backend and frontend tests and confirm the new assertions fail for the current implementation.

## Task 2: Make return method and shipment authority backend-enforced

**Files:**
- Modify `app/Models/OrderRefund.php`
- Modify `app/Services/OrderRefundService.php`
- Modify `app/Services/Logistics/SourceShipmentService.php`
- Modify `app/Http/Controllers/Api/StaffOrderController.php`
- Modify `app/Http/Controllers/UserSide/OrderController.php`
- Modify or add focused feature tests from Task 1

- [ ] Add a small canonical resolver for `shop_owned` versus `third_party`, treating an explicit third-party/customer return as authoritative even if a stale Shop-owned shipment or legacy staff carrier value exists.
- [ ] Require valid third-party courier, tracking, rider/contact, and link data at the Staff endpoint/service boundary; only then set `return_source=customer`, store the third-party fields, set `return_status=in_transit`, and timestamp the transport start.
- [ ] Clear conflicting Shop-owned return fields for the new third-party state while retaining non-authoritative audit metadata where existing history requires it.
- [ ] Ensure third-party Staff tracking never calls `ensureRefundReturnShipment()` and cannot use a Shop-owned shipment as an authority.
- [ ] Enforce Shop-owned coverage before arranging a return pickup, fail closed when coverage cannot be verified, and keep third-party arrangement unaffected by Shop coverage.
- [ ] Guard direct shipment-creation paths so third-party returns cannot create or reuse a Shop-owned shipment; preserve historical rows rather than deleting active/custody history.
- [ ] Make company `confirmReturnReceived` method-aware: Shop-owned requires the canonical Shop-owned shipment to be delivered; third-party requires `in_transit` plus explicit Staff physical-receipt confirmation before inspection/refund progression.
- [ ] Scope all added lookups to the current shop owner/customer context and avoid exposing stale shipment identifiers or rider data in third-party payloads.
- [ ] Re-run the focused backend tests after the service/controller changes.

## Task 3: Correct Staff API semantics and React UI

**Files:**
- Modify `app/Http/Controllers/Api/StaffOrderController.php`
- Modify `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Modify `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify the frontend tests from Task 1

- [ ] Add a batched, tenant-scoped POS-origin projection to Staff order payloads and suppress receipt-pending/Activate Receive UI for POS/walk-in sales without changing ordinary shipped retail behavior.
- [ ] Render the full Staff-visible dispute details (reason, customer notes/details, status, reported date, and existing authorized evidence) in the View modal using escaped React text and existing styling conventions.
- [ ] Make Arrange Return Pickup show Shop-owned logistics as unavailable outside coverage while leaving third-party selectable, with clear method-specific messaging.
- [ ] Make Staff return action eligibility mirror backend authority: third-party can proceed to physical receipt confirmation only after valid tracking makes it `in_transit`; Shop-owned waits for canonical shipment delivery.
- [ ] Render customer return tracking from customer/third-party fields for third-party returns and from Shop-owned shipment fields only for Shop-owned returns; never display stale “SoleSpace Shop Logistics” data for explicit third-party returns.
- [ ] Add/adjust accessible labels and stable test selectors only where needed; avoid broad UI redesign or speculative abstractions.
- [ ] Run the focused frontend tests.

## Task 4: Simplify, review, and verify

**Files:**
- Changed files only; `docs/ai-learning-log.md` only if a durable project lesson is discovered

- [ ] Run the ponytail simplification pass over the changed code and remove duplication or unnecessary abstractions introduced by the fix.
- [ ] Perform sequential Standards, Spec/acceptance, correctness/risk, TypeScript/React, code-splitting, and security reviews; record `N/A` where a gate does not apply.
- [ ] Run `git diff --check`, focused PHPUnit tests, frontend tests, and `pnpm run build`.
- [ ] Refresh tracked `public/build` from the verified frontend build and confirm only expected generated assets changed.
- [ ] Run the relevant Laravel test suite if the focused tests pass and time permits; report any environment limitation exactly.
- [ ] Perform reuse/dead-code checks and inspect the final diff for tenant scoping, payment/receipt semantics, stale shipment authority, and no `.env`/vendor/node_modules changes.
- [ ] Summarize behavior and verification evidence before any commit or push decision.

## Task 5: Commit and hand off

- [ ] Confirm the worktree contains only this task’s intentional changes plus refreshed tracked build assets.
- [ ] Create a focused commit with the retail Job Orders/return logistics fix.
- [ ] Push only if explicitly requested for this current task; otherwise provide the commit and exact PR-ready branch state.
