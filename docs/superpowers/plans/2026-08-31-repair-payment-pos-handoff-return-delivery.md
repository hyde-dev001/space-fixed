# Repair Payment, POS, Handoff, and Return-Delivery Workflow Dead Ends Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

Goal: Make repair payment collection, physical intake, Repairer release, Dispatcher delivery, customer return handoff, POS eligibility, and shop-rider coverage follow the existing authoritative services and role boundaries without adding a repair status.

Architecture: Add one associative-array summary method to PaymentSettlementService that exposes both the total unpaid balance and the exact amount collectible in the current phase. Existing repair, POS, and logistics controllers consume that summary for response shaping and server-side guards; existing checkout, shipment, and idempotency mechanisms remain authoritative. Frontend pages render server-provided values and invalidate coverage by the complete current method/address key.

Tech Stack: Laravel 12, PHP 8.2, Eloquent, PHPUnit feature tests, Inertia/React 18, TypeScript 5.7, Vitest, Tailwind CSS 4, pnpm.

---

## File map and boundaries

Backend:

- app/Services/PaymentSettlementService.php — authoritative repair payment summary.
- app/Services/RepairDeliveryService.php — shared transactional customer return-handoff mutation and existing logistics/payment-plan rules.
- app/Http/Controllers/Api/RepairWorkflowController.php — Repairer/shop-owner response mapping, company Repairer release/handover authorization, intake/release guards, and Dispatcher handoff integration.
- app/Http/Controllers/Api/RepairRequestController.php — customer response mapping and remaining-payment/tracking/pickup guards.
- app/Http/Controllers/Api/Logistics/ShipmentController.php — dispatcher proof-approval integration for shop-rider repair returns.
- routes/web.php — keep the Repairer route for non-dispatcher physical handover only; reject it for shop-rider delivery.
- routes/shop-owner-api.php — preserve the individual Shop Owner repair handoff route.

Frontend:

- resources/js/Pages/ERP/cashier/POS.tsx — server-provided collectible phase and amount.
- resources/js/Pages/ERP/repairer/POS.tsx — same server contract for Repairer POS.
- resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx — remove Repairer receive activation and show derived waiting-for-payment state.
- resources/js/Pages/UserSide/Repairs/myRepairs.tsx — next actions, third-party tracking gate, and fresh coverage state.

Tests:

- Create tests/Feature/Repair/RepairPaymentSummaryTest.php for the two-value summary contract.
- Update tests/Feature/RepairPosPaymentFlowTest.php for POS scope, amount, tenant, cancellation, and idempotency.
- Update tests/Feature/Repair/RepairLogisticsIntakeTest.php for the bring-to-shop sequence.
- Update tests/Feature/Repair/RepairLogisticsPaymentTest.php for payment-phase regressions.
- Update tests/Feature/Repair/RepairReturnHandoffTest.php for tracking, release, dispatcher, and unpaid gates.
- Update tests/Feature/Repairer/RepairerWorkflowTest.php and tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php for authority compatibility.
- Update resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx.
- Update resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx.
- Update resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx, repairShopCoverageIntegration.test.tsx, and myRepairs.layout.test.ts.

No migration is planned. Existing POS uniqueness/idempotency constraints and repair payment tables remain the final race-condition protections.

## Execution rules

- Follow superpowers:executing-plans inline and sequentially. The repository explicitly disallows subagents for this task unless the user separately approves the optional parallel-review gate.
- Use superpowers:test-driven-development for each behavior change: add a focused failing regression, run it, implement the smallest change, then rerun.
- Apply laravel-best-practices to transactions, authorization, validation, eager loading, and query count.
- Apply security-review to role and payment mutations.
- Apply vercel-react-best-practices, typescript-advanced-types, and karpathy-guidelines to changed TS/TSX.
- Reuse existing components and avoid a broad shared POS refactor.
- Keep commits scoped and preserve unrelated work outside this worktree.

## Task 1: Install dependencies and capture the clean baseline

Files: no source changes.

- [ ] Verify the worktree and tools.

    git status --short --branch
    php --version
    composer --version
    node --version
    npm.cmd --version

    Expected: clean feature branch based on origin/solespace-b, PHP 8.2, Composer, and Node/npm available. Do not touch the original registration worktree.

- [ ] Install PHP dependencies.

    composer install --no-interaction --prefer-dist

    Expected: vendor is created without changing composer.lock. Request network approval if the sandbox blocks downloads.

- [ ] Install frontend dependencies with the declared pnpm version.

    pnpm install --frozen-lockfile

    If pnpm is unavailable, use the equivalent npm.cmd exec invocation for pnpm@11.14.0 without changing package.json or pnpm-lock.yaml.

- [ ] Run and record the narrow baseline.

    php artisan test tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repairer/RepairerWorkflowTest.php
    pnpm run test:frontend -- resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx

    Expected: baseline results are recorded before production changes. Existing failures are isolated and documented.

## Task 2: Define and test the two-value payment summary

Files:
- Create tests/Feature/Repair/RepairPaymentSummaryTest.php.
- Modify app/Services/PaymentSettlementService.php.

- [ ] Add failing tests for:
  1. A partially settled initial phase where outstanding_balance is greater than collectible_amount.
  2. A ready repair whose current collectible phase is the final balance.
  3. Fully paid, cancelled, rejected, refunded, and reconciliation-blocked repairs returning collectible false and zero collectible_amount.
  4. Recovery-only phases not being mislabeled as ordinary POS deposit or balance collection.

  Assert exact decimal values and due_type/phase fields. The first case proves that callers cannot substitute one amount for the other.

- [ ] Run the focused test and confirm failure.

    php artisan test tests/Feature/Repair/RepairPaymentSummaryTest.php

    Expected: failure because the public summary contract does not yet exist or lacks one of the two values.

- [ ] Add a public method such as repairCollectionSummary(RepairRequest $repair) to PaymentSettlementService.

  It must reuse isRepairPaymentDueNow, isRepairSettled, isRepairPaymentPhaseSettled, resolveRepairPaymentPhase, and repairPaymentBreakdown; reuse/promote the existing ledger/session balance helper for outstanding_balance; return the exact current breakdown total as collectible_amount; expose grand total, paid total, service amount, delivery amount, phase, due type, and fully_paid; and treat cancelled/rejected/refunded/reconciliation-blocked records as non-collectible without mutating them.

  Keep the existing array style. Do not add a value-object package or persistence.

- [ ] Rerun the focused summary test. Expected: pass.

- [ ] Commit:

    git add app/Services/PaymentSettlementService.php tests/Feature/Repair/RepairPaymentSummaryTest.php
    git commit -m "feat: expose repair payment collection summary"

## Task 3: Thread the summary through repair APIs and server-side gates

Files:
- Modify app/Http/Controllers/Api/RepairWorkflowController.php.
- Modify app/Http/Controllers/Api/RepairRequestController.php.
- Update tests/Feature/RepairPosPaymentFlowTest.php.
- Update tests/Feature/Repair/RepairLogisticsPaymentTest.php.
- Update tests/Feature/Repair/RepairReturnHandoffTest.php.

- [ ] Add failing feature assertions that customer, Repairer, and scope=pos_checkout responses expose collectible_amount, outstanding_balance, due_type, phase, and fully_paid. Add a ready/unpaid release regression and a fully settled release case.

- [ ] Run:

    php artisan test tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairReturnHandoffTest.php

    Expected: failure on missing fields or status-string-based gates.

- [ ] Map the summary in myAssignedRepairs and myRepairs. Preserve compatibility fields, but make outstanding_balance and fully_paid originate from the summary.

  For scope=pos_checkout, omit or mark records with no ordinary deposit, full, or balance collection. Keep tenant and authorization filters.

- [ ] Replace duplicate release, ship, customer pickup, and related handoff inference at mutation gates with the summary/fully_paid result under existing row locks. Preserve initial-payment semantics for physical intake; payment must not update receipt fields.

- [ ] Rerun Task 3 tests and the summary test. Expected: pass, including VAT, refund, reconciliation, and payment-session coverage.

- [ ] Commit:

    git add app/Http/Controllers/Api/RepairWorkflowController.php app/Http/Controllers/Api/RepairRequestController.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairReturnHandoffTest.php
    git commit -m "feat: use authoritative repair balance in workflow APIs"

## Task 4: Close the bring-to-shop/POS backend dead end

Files:
- Modify app/Http/Controllers/Api/RepairWorkflowController.php.
- Update tests/Feature/Repair/RepairLogisticsIntakeTest.php.
- Update tests/Feature/RepairPosPaymentFlowTest.php.
- Update tests/Feature/Repair/RepairLogisticsPaymentTest.php.

- [ ] Add the failing sequence: walk-in request, Repairer acceptance, POS list, initial payment, and physical receipt still separately required. Assert the existing repair id is charged only the server collectible amount. Also cover ready/final balance and exclude fully paid/cancelled/rejected records.

- [ ] Run:

    php artisan test tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php

    Expected: failure because the POS scope/status and response contract omit a legitimate payment stage.

- [ ] Use intake_delivery_method for acceptance branch and message selection, with delivery_method only as a compatibility fallback. Do not add a repair status.

- [ ] Verify RepairPosPaymentService remains the enforcement point for exact server amount, repair tenant, due phase, and idempotency. Make only the smallest change needed to expose a legitimate phase.

- [ ] Rerun focused tests. Expected: pass for initial and final collection while physical intake remains separate.

- [ ] Commit:

    git add app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php
    git commit -m "fix: make accepted repairs collectible in POS"

## Task 5: Separate Repairer release from Dispatcher delivery handoff

Files:
- Modify app/Services/RepairDeliveryService.php.
- Modify app/Http/Controllers/Api/RepairWorkflowController.php.
- Modify app/Http/Controllers/Api/Logistics/ShipmentController.php.
- Modify routes/web.php.
- Preserve routes/shop-owner-api.php unless route naming/authorization needs a surgical fix.
- Update tests/Feature/Repair/RepairReturnHandoffTest.php.
- Update tests/Feature/Repairer/RepairerWorkflowTest.php.
- Update tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php.
- Modify resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx.
- Update resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx.

- [ ] Add failing tests asserting:
  - the assigned Repairer can record actual walk-in/customer-courier handover after full payment;
  - the assigned Repairer cannot use that customer-handover action for shop-rider delivery;
  - individual Shop Owner can retain the existing repair handoff route;
  - company Shop Owner access does not replace the company Repairer flow;
  - Repairer mark-ready is the pre-dispatch shop-rider release and does not set pickup_enabled;
  - dispatcher approval of an approved repair_return proof activates the existing customer receive state for shop delivery only after full payment;
  - unapproved proof, unpaid balance, wrong tenant, and replay remain blocked/idempotent; and
  - warranty notification behavior remains intact.

- [ ] Run:

    php artisan test tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php

    Expected: failure because the Repairer release/handoff boundary and Dispatcher approval path do not yet match the approved actor matrix.

- [ ] Extract the common transactional pickup_enabled/timestamp/actor/lock updates, method/status/proof/full-payment validation, notification, and audit behavior into RepairDeliveryService. Accept the assigned Repairer actor for walk-in/customer-courier handover and the authorized Dispatcher actor for approved shop-rider delivery; keep existing fields/statuses.

- [ ] Keep the individual Shop Owner path compatible, while making the company Repairer path method-aware: allow assigned Repairer walk_in/customer_pickup actual handover, and reject company Repairer shop_delivery so it cannot mark customer receipt before Dispatcher delivery.

- [ ] Keep a clearly labeled Repairer handover action for walk_in/customer_pickup, remove any shop-rider handoff button, and keep read-only shop-rider shipment/proof information. Preserve Dispatcher logistics routes.

- [ ] Within the existing dispatcher proof-approval transaction, after approving and marking a repair_return leg delivered, invoke the shared operation for the linked repair. Restrict it to source_type repair_request and purpose repair_return; leave retail, intake, return-to-shop, and unrelated proof flows unchanged.

- [ ] Rerun focused tests. Expected: company Repairer non-dispatcher handover passes, company Repairer shop-rider handoff calls fail without mutation, individual Shop Owner handoff remains valid, Dispatcher shop delivery approval activates receive, and proof/notification/warranty tests stay green.

- [ ] Commit:

    git add app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairWorkflowController.php app/Http/Controllers/Api/Logistics/ShipmentController.php routes/web.php routes/shop-owner-api.php tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
    git commit -m "fix: route repair handoff through Repairer and Dispatcher"

## Task 6: Enforce unpaid third-party return gates

Files:
- Modify app/Http/Controllers/Api/RepairRequestController.php.
- Update tests/Feature/Repair/RepairReturnHandoffTest.php.
- Update tests/Feature/Repair/RepairLogisticsPaymentTest.php.

- [ ] Add failing tests for a ready customer-pickup/third-party return with positive outstanding_balance: response fields, retry payment amount, tracking rejection with the exact required message, customer pickup/release rejection, and post-settlement unlock.

- [ ] Run:

    php artisan test tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php

    Expected: failure because tracking lacks a consistent balance gate.

- [ ] Inside existing customer authorization and row-lock paths, use the summary/fully_paid result before writing external tracking, confirming pickup, or initiating a return handoff. Preserve sponsored warranty/no-charge and recovery exceptions through canonical rules.

- [ ] Ensure retryPaymentSession returns the settlement breakdown amount and never derives a remaining amount from React fields or trusts a stale client amount.

- [ ] Rerun focused tests and commit:

    git add app/Http/Controllers/Api/RepairRequestController.php tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php
    git commit -m "fix: block unpaid repair return handoff"

## Task 7: Replace both POS pages' local amount inference

Files:
- Modify resources/js/Pages/ERP/cashier/POS.tsx.
- Modify resources/js/Pages/ERP/repairer/POS.tsx.
- Update resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx.
- Create/update resources/js/Pages/ERP/repairer/__tests__/POS.repair-checkout.test.tsx only if no equivalent exists.

- [ ] Add failing frontend assertions with grand_total 1000, total_paid_amount 300, outstanding_balance 700, collectible_amount 200, and due_type balance. Assert that 200 is displayed/submitted, total/paid/balance/phase are shown, and no grand_total / 2 or frontend total-paid calculation is used.

- [ ] Run:

    pnpm run test:frontend -- resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx resources/js/Pages/ERP/repairer/__tests__/POS.repair-checkout.test.tsx

    Expected: failure because both pages infer due type and halve totals. Omit the optional file if it does not exist.

- [ ] Add typed summary fields to each page and map collectible_amount, outstanding_balance, due_type, and phase. Keep the two existing page implementations aligned without a broad shared POS refactor.

- [ ] Remove or stop using resolveOutstandingDueType and computeDueAmountForOrder. The selected order line price and submitted due type come directly from the API summary.

- [ ] Preserve existing repair id, due type, payment lines, and idempotency payload. Refresh/reject a stale order instead of charging a locally recalculated amount.

- [ ] Rerun focused tests and commit:

    git add resources/js/Pages/ERP/cashier/POS.tsx resources/js/Pages/ERP/repairer/POS.tsx resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx resources/js/Pages/ERP/repairer/__tests__/POS.repair-checkout.test.tsx
    git commit -m "fix: use server collectible amount in repair POS"

## Task 8: Add Repairer derived waiting-for-payment presentation

Files:
- Modify resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx.
- Update resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx.

- [ ] Add failing UI tests for a ready repair with positive outstanding_balance showing Ready for Pickup plus Waiting for Payment, zero balance hiding the indicator, and no Repairer activate/receive button or POST.

- [ ] Run:

    pnpm run test:frontend -- resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx

    Expected: failure on the old action and missing indicator.

- [ ] Add outstanding_balance to the local response type and render the secondary badge from the API value. Remove duplicate status/policy math used only for presentation where the summary supplies the answer.

- [ ] Rerun tests and commit:

    git add resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
    git commit -m "feat: show Repairer waiting for payment state"

## Task 9: Fix customer next actions, payment action, and coverage freshness

Files:
- Modify resources/js/Pages/UserSide/Repairs/myRepairs.tsx.
- Update resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx.
- Update resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx.
- Update resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts.

- [ ] Add failing UI tests for:
  - accepted walk-in with positive balance showing the bring-to-shop/payment message;
  - accepted walk-in with satisfied initial payment showing physical-drop-off instruction;
  - ready customer-pickup/third-party return with positive balance showing Pay Remaining Balance;
  - unpaid external tracking hidden/disabled with the required explanation;
  - zero balance unlocking tracking after refreshed data;
  - ready plus positive balance showing the derived waiting-for-payment label; and
  - no raw or replacement calculated payment data submitted by the browser.

- [ ] Add coverage tests for delayed response A after switching to B, A to B to A, and changing away from shop_delivery so stale errors/fees disappear.

- [ ] Run:

    pnpm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts

    Expected: failure on missing messages/payment action, stale coverage, or old tracking behavior.

- [ ] Add typed summary fields and replace customer-side paid/total inference for affected labels/actions. Keep existing payment-session confirmation and refresh behavior.

- [ ] For customer_pickup return, render history read-only if useful but disable/hide actionable tracking while outstanding_balance is positive. Do not show Shop-rider coverage errors for customer pickup or walk-in.

- [ ] Build a coverage key from return method, address identity, coordinates, and any revision available. Clear coverage/error/quote/accepted fee whenever the key changes, fetch only for shop_delivery, and ignore responses whose captured key is no longer current. Keep backend save/requote authoritative.

- [ ] Rerun focused tests and commit:

    git add resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts
    git commit -m "fix: refresh repair customer payment and coverage state"

## Task 10: Sequential review, dead-code scan, and quality gates

Files: only changed files if review discovers a real issue.

- [ ] Run the focused backend suite.

    php artisan test tests/Feature/Repair/RepairPaymentSummaryTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php

    Expected: pass.

- [ ] Run the focused frontend suite.

    pnpm run test:frontend -- resources/js/Pages/ERP/cashier/__tests__/POS.repair-checkout.test.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts

    Expected: pass. Include the optional Repairer POS test if created.

- [ ] Perform the sequential review stack and record each result:
  1. Simplify: remove duplicated helpers, unnecessary state, and speculative abstractions.
  2. Standards/spec/correctness: compare the diff with repository conventions and approved acceptance criteria.
  3. TypeScript/React: check typed boundaries, effect dependencies, stale async responses, accessibility, and bundle impact. Do not claim lint/typecheck without configured tooling.
  4. Karpathy: verify assumptions, minimal scope, dead-code removal, and concrete success criteria.
  5. Security: verify tenant scoping, role authorization, row locks, exact server amounts, and no browser-only enforcement.

- [ ] Run repository quality gates:

    git diff --check
    pnpm run build
    composer test

    Expected: applicable commands pass. Report exact unrelated baseline failures instead of masking them.

- [ ] Scan changed areas for activate-pickup, computeDueAmountForOrder, resolveOutstandingDueType, old frontend balance inference, and coverage state that omits return method. Confirm company Repairer physical handover and Dispatcher shop-rider delivery routes remain, and the individual Shop Owner repair handoff route is preserved.

- [ ] Add a short non-sensitive docs/ai-learning-log.md entry only if this work reveals a durable rule not already documented; otherwise leave it unchanged.

- [ ] Record final status:

    git status --short --branch
    git log --oneline -n 12

    Final report must identify six root causes, changed files, eligibility/payment rules, tests, quality-gate results, and remaining edge cases. Do not claim completion without fresh evidence.

## Completion record

- Implementation completed in the isolated `fix/repair-payment-pos-handoff-return-delivery` worktree.
- Focused Laravel repair/payment/handoff suite passed: 91 tests, 876 assertions. Existing fixture `file_get_contents` warnings remain.
- Focused frontend repair/POS/logistics suite passed: 5 files, 61 tests. The cashier endpoint regression also passed independently: 2 tests.
- `git diff --check`, PHP syntax checks for changed backend files, and `pnpm run build` passed. Generated `public/build` output was cleaned after the build.
- The separate `RepairPosPaymentFlowTest` regression still has the two pre-existing Shop Owner guard failures (expected 409/200, received 302); no unrelated Shop Owner behavior was changed to mask them.
- Full `composer test` was attempted but timed out after 300 seconds with unrelated route-catalog and logistics-actor-policy failures.
- No code commit was created; the worktree contains the implementation changes for review.
