# Repair POS Control Hardening Design

Date: 2026-04-03
Status: Approved
Scope: Backend controls plus minimum Repairer UI parity

## Context

The repair POS flow persists payment and refund data in repair and POS ledger tables, but current control coverage has high-risk gaps: weak checkout authorization scope, duplicate phase charges, non-cash auto-settlement without verification, refund aggregation inconsistencies, and inconsistent payment status fields.

## Selected Decisions

1. Delivery scope: backend controls plus minimum Repairer UI parity.
2. Authorization model: strict policy + workflow-state guards.
3. Idempotency model: hybrid (DB hard lock + request idempotency key replay).
4. Non-cash settlement: hybrid (gateway verification first, manual approval fallback with risk flag).
5. Canonical status: `payment_status` only (phased deprecation of `payment_status_derived`).
6. Refund role parity: Repairer can request refunds; Shop Owner approves/executes.
7. Architecture strategy: strangler rollout over existing endpoints.

## Architecture

Keep existing API entrypoints stable and move financial controls into a dedicated orchestration layer.

1. Controller layer: request shape validation + actor resolution only.
2. Policy layer: same-shop + role + workflow-state checks for mutation routes.
3. Payment orchestration: idempotency replay, phase-lock enforcement, tender state transitions, canonical status synchronization.
4. Ledger persistence: transaction envelope (`pos_transactions`), tenders (`pos_payment_lines`), receipts (`pos_receipts`), refunds (`pos_refunds`) as auditable source for financial events.
5. Read APIs: persisted transaction/refund history for Repairer and Shop Owner, role-scoped actions.

## Data Flow

### Checkout

1. UI sends checkout with `repair_request_id`, `due_type`, `payment_lines`, `idempotency_key`.
2. Backend authorizes actor via policy and validates due phase against repair workflow state.
3. Backend enforces idempotency replay and phase uniqueness.
4. Backend writes transaction and payment lines:
   - cash lines settle immediately;
   - card/wallet lines start at `pending_authorization`.
5. Backend synchronizes canonical `payment_status` from ledger outcomes.
6. Receipt is issued as provisional or final based on settlement completeness.

### Non-cash verification

1. Verifiable references: gateway/webhook confirmation path.
2. Non-verifiable references: manager/manual approval path with risk flag.
3. Settlement updates line status, transaction status, and repair status deterministically.

### Refunds

1. Repairer creates refund request from transaction history.
2. Refundable amount is computed from aggregate successful paid minus aggregate succeeded refunds.
3. Shop Owner approves/executes refund.
4. Refund execution updates refund lifecycle, source transaction status, and canonical repair payment status.
5. Cancel-triggered auto-refund uses aggregate paid basis, never latest transaction only.

### Walk-in refunds

Walk-in refunds are ledger-driven, not account-driven. Staff initiates requests using transaction/receipt identity and captured walk-in contact fields. Approval/execution is still role-gated. Split tenders refund against original lines to avoid over-refunding.

## Security Controls

1. Enforce shop scope and allowed role checks on every payment/refund mutation endpoint.
2. Apply workflow-state guards per due phase.
3. Enforce one successful phase settlement per repair unless explicitly voided/reversed.
4. Require idempotency keys for checkout.
5. Add immutable actor/status transition metadata for auditability.

## Error Handling Contract

Use deterministic machine codes:

- `AUTH_FORBIDDEN_SHOP_SCOPE`
- `AUTH_FORBIDDEN_ROLE`
- `WORKFLOW_STATE_INVALID`
- `PAYMENT_PHASE_ALREADY_SETTLED`
- `IDEMPOTENCY_REPLAY`
- `NON_CASH_PENDING_VERIFICATION`
- `REFUND_AMOUNT_EXCEEDS_REFUNDABLE`

Expected behavior:

1. replay responses return the original transaction payload with a replay flag;
2. phase duplicates return `409`;
3. validation returns `422` with field keys;
4. authorization returns `403`.

## Testing Strategy

1. Authorization matrix tests (shop mismatch, role mismatch, workflow mismatch).
2. Idempotency tests (same key replay, different key same phase blocked).
3. Concurrency tests for race-safe phase locking.
4. Non-cash pending/settled/failed lifecycle tests.
5. Refund aggregate correctness across multiple payments and partial refunds.
6. Canonical payment status consistency tests after payment/refund transitions.
7. Repairer history and request-only refund UI/API tests.

## Rollout Plan

1. Phase 1 (P0): authorization, idempotency, phase lock, refund aggregate correctness, canonical status synchronization.
2. Phase 2 (P1): non-cash verification lifecycle, repairer history API + UI, repairer refund request flow.
3. Phase 3 (P2): receipt enrichment for registered customers and remaining UX harmonization.

## Acceptance Criteria

1. Unauthorized actors cannot mutate payment/refund state.
2. Duplicate due-phase charges are blocked under retry and race conditions.
3. Non-cash tenders are not auto-settled without verification/approval.
4. Multi-payment refunds use aggregate math and update canonical status correctly.
5. Repairer has persisted transaction visibility and can submit refund requests.
6. Audit trail includes actor, state transition, and reason metadata for every financial mutation.
