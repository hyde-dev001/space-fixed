# myRepair Mixed Refund Split-Settlement Design

Date: 2026-04-07
Status: Approved
Scope: myRepair refund flow only (customer-initiated from myRepairs)

## 1. Objective

Design a single customer-facing myRepair refund workflow that can correctly handle refunds when payment sources are mixed (online and POS), without forcing customers to create separate refund tickets.

## 2. Scope Boundaries

Included:
- Customer submits refund from myRepairs only.
- Repairer-first evidence review, then finance/owner approvals based on policy.
- Finance executes refund release.
- Split-settlement computation and execution handled internally under one refund case.

Excluded:
- Product order refunds.
- Staff-initiated POS refunds as the primary customer refund channel.
- Any change to non-myRepair refund modules.

## 3. Core Policy Decisions

1. Routing basis: payment-source based, not return-method based.
2. Customer creates one refund request only.
3. If refundable amount has multiple payment sources, system creates internal refund legs.
4. Evidence policy for POS-involved refunds:
- Receipt proof required.
- Flexible media minimum (at least one clear photo); no strict 5-image+1-video package.
5. First gate is Repairer; Finance is the final executor.
6. Rollout policy: new flow applies to new refund records only.

## 4. Architecture

### 4.1 Parent Case (Single Customer Ticket)

A single myRepair refund request acts as parent case:
- customer reason
- evidence bundle
- stage statuses
- total requested/refundable amounts

Customer sees one case and one timeline.

### 4.2 Internal Refund Legs (System-Managed)

If needed, system creates child legs:
- `gateway` leg for online-paid refundable portion
- `pos_manual` leg for in-shop/POS refundable portion

Leg records include:
- requested amount
- approved amount
- execution status
- source references (gateway payment id, POS transaction/receipt)

## 5. End-to-End Data Flow

1. Customer submits refund from myRepairs.
2. Backend computes refundable source breakdown:
- online refundable amount
- POS refundable amount
3. Backend creates one parent case.
4. Backend creates one or more internal legs under that case.
5. Repairer reviews evidence first.
6. Finance reviews after repairer endorsement.
7. Owner review runs only if policy requires it.
8. Finance executes each approved leg.
9. Parent case derives final state from all legs.

## 6. Approval and Execution Responsibilities

### 6.1 Repairer First Gate

Repairer is the first reviewer and first decision-maker:
- validates technical/service-related evidence
- approves or rejects for finance routing

### 6.2 Finance Stage

Finance performs financial governance and executes release:
- approves/rejects based on policy and records
- executes refund per leg (`gateway` or `pos_manual`)

### 6.3 Owner Stage (Conditional)

Owner approval is optional and policy-driven.

### 6.4 Individual Shop (No Staff/Employees) Rule

For individual shops where one owner manages all operations:
- The same owner account may perform both repairer-stage and finance-stage actions.
- Stage boundaries are preserved even with one actor (technical review first, financial execute second).
- Audit logs must remain stage-separated (same actor allowed, different action records/timestamps).
- Finance execution still uses POS-backed context and does not require navigating to the POS checkout UI.

## 7. Walk-in / Pick-up Scenario Rule

Case:
- Intake method: "I'll Visit the Shop"
- Return method: "Customer Pick-up at Shop"
- Payment source: walk-in/POS only

Behavior:
1. Customer still submits one myRepair refund request.
2. Source split becomes:
- online leg: 0
- POS leg: > 0
3. System creates POS-only leg.
4. Receipt proof is required.
5. Finance executes manual/POS release record.
6. No gateway refund call is made.

## 7.1 Customer-Arranged Delivery + Shop Pick-up Scenario Rule

Case:
- Intake method: "I'll Arrange Delivery (e.g., Lalamove)"
- Return method: "Customer Pick-up at Shop"

Behavior:
1. Customer still submits one myRepair refund request.
2. Refund routing still follows payment source, not delivery option.
3. Common outcomes:
- online-only payment: gateway leg only
- mixed payment (online + POS): split legs
- POS-only payment: POS leg only
4. If a POS leg exists, receipt proof is required.

## 8. Finance Visibility Requirement

Finance does not need to navigate to POS page, but must see POS transaction context in refund approval UI:
- source transaction number
- receipt number
- paid amount and refundable balance
- tender type/customer type
- evidence and receipt proof

Execution is blocked when required POS references are missing or invalid.

## 9. Error Handling and Guardrails

1. Refund not eligible:
- requested amount > refundable balance -> reject.

2. Duplicate submission:
- idempotent parent create and idempotent per-leg execution.

3. Partial execution:
- if one leg succeeds and another fails, parent moves to partial-failed state with retry action for failed leg only.

4. Evidence integrity:
- POS-involved refunds require receipt proof.

5. Immutable audit:
- every stage decision and leg execution stores actor/time/reason.

## 10. Rollout Strategy

1. Feature flag for split-leg creation.
2. Apply to new refund cases only.
3. Keep legacy records on legacy handling.
4. Monitor:
- duplicate rate
- per-leg failure rate
- finance retry counts
- stage SLA timings

## 11. Testing Plan

1. Submit flow tests:
- online-only -> one gateway leg
- POS-only -> one POS leg
- mixed -> two legs with exact amounts

2. Governance tests:
- repairer endorsement required before finance action
- finance-only execution
- owner optional branch

3. Execution tests:
- success/success => executed
- success/fail => partial failed
- retry only failed leg

4. Evidence tests:
- POS leg requires receipt proof
- media validation with flexible minimum

## 12. Success Criteria

1. myRepair customers always submit one refund case.
2. Mixed payment refunds are settled accurately without misclassifying online amounts as over-the-counter.
3. Repairer remains first gate.
4. Finance executes all releases with complete source visibility.
5. Walk-in-only payment refunds run POS-only leg without unnecessary gateway operations.

## 13. Implementation Plan (Execution-Ready)

### Phase 1: Data and Contract Preparation

Objective: support split-leg mixed refunds and finance-proofed POS execution in one parent case.

Tasks:
1. Add/confirm leg-level fields for `gateway` and `pos_manual` legs:
- `leg_type`
- `requested_amount`
- `approved_amount`
- `execution_status`
- `source_transaction_id`
- `source_receipt_no`
2. Add POS execution proof fields (required at execute time for `pos_manual`):
- `execution_channel` (`gcash`, `card`, `bank_transfer`, `manual_cash`)
- `execution_reference`
- `execution_amount`
- `executed_at`
- `executed_by`
- `execution_proof_urls` (at least one proof file)
3. Add customer payout preference fields for POS leg requests:
- `preferred_return_channel`
- `preferred_return_account_name`
- `preferred_return_account_ref`
- `customer_payout_consent` (boolean)

Acceptance checks:
1. Mixed refund can persist two legs under one parent.
2. POS leg cannot be marked executed without complete execution proof fields.

### Phase 2: Backend Workflow Enforcement

Objective: enforce governance and correct routing based on payment source.

Tasks:
1. On myRepair submit, compute refundable breakdown and create legs automatically:
- online amount -> `gateway`
- POS amount -> `pos_manual`
2. Keep stage order enforcement:
- repairer decision before finance decision
- finance execution only after approved stage
3. Add execution-time validations for POS leg:
- source transaction/receipt must exist
- execution proof fields must be present
- execution amount must not exceed approved amount
4. Add fallback policy for card constraints:
- if original-card-only rule applies, require same-rail return
- otherwise allow alternate customer-provided destination with consent record

Acceptance checks:
1. Finance cannot execute when required proof/reference is missing.
2. Parent status derives correctly from leg outcomes (`succeeded`, `partial_failed`, `failed`).

### Phase 3: Frontend Changes (myRepair + Finance)

Objective: collect required customer preference data and expose finance execution controls with audit-safe visibility.

Tasks:
1. myRepair refund form:
- keep one refund submission path
- when POS amount exists, require payout preference fields
- require at least one clear proof upload for POS-involved case
2. Finance refund detail page:
- show POS context (source transaction, receipt no, paid amount, refundable balance, tender type)
- show payout preference from customer
- provide execute form for POS leg with required execution proof fields
3. Customer timeline view:
- show one case with per-leg progress and references
- show redacted finance proof summary only

Acceptance checks:
1. Customer can submit one request for mixed payment and see one timeline.
2. Finance can execute POS leg in-place without opening POS checkout page.

### Phase 4: Proof Visibility and Permission Matrix

Objective: protect sensitive payment evidence while preserving auditability.

Rules:
1. Full proof access:
- finance executor
- finance approver/head finance
- authorized owner/auditor
2. Limited operational access:
- repairer/cashier: status and minimal summary only (no full sensitive reference by default)
3. Customer access:
- redacted proof summary only (masked reference, amount, date, channel, status)
4. Individual shop rule:
- same owner account may act across stages
- logs must remain stage-separated with distinct action records/timestamps

Acceptance checks:
1. Unauthorized roles cannot view full proof payload.
2. Customer view never exposes raw full payment proof artifacts.

### Phase 5: Rollout, Monitoring, and QA Gate

Objective: safe rollout for new records only with measurable quality gates.

Tasks:
1. Enable feature flag for split-leg flow in staging.
2. Run integration tests for:
- online-only
- POS-only
- mixed with both legs
- POS execute validation failure paths
3. Enable production for new refund cases only.
4. Monitor first 2 weeks:
- execution failure rate by leg type
- missing-proof rejection count
- finance retry volume
- mean processing time by stage

Release gate:
1. No P1/P2 defects on mixed refund execution.
2. Proof enforcement pass rate is 100 percent in finance execute path.
3. Customer timeline remains single-case and consistent with leg statuses.
