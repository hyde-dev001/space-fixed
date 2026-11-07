# Retail refund and invoice QA fix

## Acceptance criteria

- No return shipment before Staff and Finance approvals and an explicit shop-owned arrangement; third-party returns never create Dispatcher shipments.
- Active return arrangements cannot be duplicated; a shop-owned arrangement may be retried only after its shipment and all legs are cancelled. Failed legs remain eligible for the existing same-shipment dispatcher retry flow.
- Resellable online returns restore the selected linked inventory, product variant and aggregate quantity once, with one StockMovement; write-offs do not increase sellable stock.
- Approved delivery proof advances an awaiting leg only when the current proof is approved; unrelated pickup, return receipt and repair transitions remain guarded.
- Invoice creation invalidates the existing invoices query so the list and derived KPIs refresh without a browser reload.

## Plan

1. Add failing backend and frontend regression tests for shipment gates/retries, linked-inventory return disposition, proof-state transitions and invoice-query invalidation.
2. Add approval/arrangement checks to the shared return shipment creator; allow retries only for terminal shop-owned return legs and expose that state to the Staff UI.
3. Reuse the existing inventory selection and movement services for online refund restocks while retaining line-level idempotency.
4. Align `markDelivered` with the proof-review state while requiring the current approved proof whenever a leg is awaiting proof review.
5. Reuse `useCreateInvoice` in the create page and run focused, then broader regression checks.

## Verification

- Laravel: refund approval/arrangement, return inspection/inventory, shipment source and proof-review feature tests.
- Frontend: Job Orders return-action and Finance invoice-query tests; full frontend suite/build if the focused checks pass.
- `git diff --check`; inspect branch diff and preserve pre-existing untracked cache data.

## Verification results

- Focused Laravel regression suite: 89 passed; one existing staff order-shipping route test returned 403.
- Refund approval eligibility: 2 passed; linked return inspection, proof-review, POS inventory, and shipment tests passed.
- Retail logistics coverage/module gate: 13 passed.
- Focused frontend tests: 12 passed; full frontend suite: 1,755 passed, 1 unrelated containment test failed because its Inertia mock lacks `Head`; production Vite build succeeded.
- POS and repair compatibility suites still contain 423 responses from the existing employee-clock-in middleware when their fixtures are not clocked in; no middleware or repair code was changed.
