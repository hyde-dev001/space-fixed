# Online/myRepair Refund Workflow Design

Date: 2026-04-03
Status: Approved
Scope: Customer online/myRepair refund flow only

## 1. Objective

Define a role-separated, auditable refund workflow for online/myRepair requests where technical validity is assessed first by the assigned repairer, then monetary governance is handled by finance, with optional shop-owner approval based on settings, and final payout execution by finance only.

## 2. Chosen Workflow

Selected path:

- repairer_review
- finance_review
- owner_review (optional)
- finance_execute

Rationale:

- Clear accountability per role
- Consistent with existing refund governance patterns
- Better auditability

Trade-off:

- More stage fields and transitions than a simple single-approver flow

## 3. Stage Responsibilities

### 3.1 Customer

- Submits refund request from myRepair with supporting evidence (photo/video)
- Sees timeline and current stage
- Cannot approve/reject/execute

### 3.2 Assigned Repairer (first gate)

- First reviewer of refund request
- Assesses submitted evidence and technical validity
- Allowed actions:
  - approve_for_finance
  - reject
- Required to provide assessment note on decision

### 3.3 Finance

- Reviews requests endorsed by repairer
- Performs financial checks and policy checks
- Allowed actions:
  - approve_initial (if owner stage required)
  - approve_final (if owner stage skipped by settings)
  - reject
  - execute payout (only after final approval)

### 3.4 Shop Owner (optional governance gate)

- Invoked only when refund approval setting requires owner stage
- Reviews finance-initial-approved requests
- Allowed actions:
  - approve_final
  - reject

### 3.5 Execution Ownership

- Finance is the only executor of payout release for online/myRepair refunds

## 4. Data Model and State Contract

## 4.1 State fields (recommended)

- workflow_stage: repairer_review | finance_review | owner_review | finance_execute | done
- overall_status: requested | in_review | approved_final | rejected | executed | failed
- repairer_status: pending | approved | rejected
- finance_status: pending | approved_initial | approved_final | rejected
- owner_status: pending | approved | rejected | skipped

## 4.2 Evidence and notes

- evidence_snapshot (immutable JSON copy at submit time)
- repairer_assessment_note (required for repairer decision)
- rejection_reason_code and rejection_reason_note per rejecting stage
- stage timestamps (reviewed_at, approved_at, rejected_at, executed_at)

## 4.3 Execution fields

- execution_mode (manual/gateway)
- executed_by (finance actor)
- executed_at
- failure_reason

## 5. Transition Rules

Canonical transitions:

1. requested_by_customer -> under_repairer_review
2. under_repairer_review -> rejected_by_repairer OR endorsed_to_finance
3. endorsed_to_finance -> rejected_by_finance OR approved_by_finance_initial
4. approved_by_finance_initial -> owner_review (if required) OR approved_final (if skipped)
5. owner_review -> rejected_by_owner OR approved_final
6. approved_final -> executed_by_finance (success/failed outcome)

Guardrails:

- Strict stage gating: no out-of-order action
- No payout execution before approved_final
- Owner stage enforced only when setting requires it

## 6. Assessment Rules (v1)

- Minimum evidence: at least 1 photo required
- Video optional in v1
- Valid examples:
  - service defect
  - unfinished repair output
  - post-service damage attributable to service process
- Invalid baseline example:
  - change of mind without service fault
- Repairer decision note required on approve/reject
- Reject requires structured reason code + note

## 7. Queue and UI Design

## 7.1 Customer myRepair page

- Shows stage badge and timeline
- Shows evidence snapshot and decision history
- Shows final outcome and reason details for rejects/failures

## 7.2 Repairer queue

- Shows only assigned requests
- Decision buttons:
  - Approve for Finance
  - Reject
- No execute capability

## 7.3 Finance queue

- Shows repairer-endorsed requests
- Finance decides initial/final approval depending on owner policy
- Finance executes payout after final approval

## 7.4 Owner queue (conditional)

- Visible only when owner approval policy is active
- Displays finance-initial-approved items
- Owner can approve final or reject

## 8. Notifications

Customer notifications:

- Request received
- Repairer endorsed or rejected
- Finance/owner final decision
- Execution succeeded or failed

Internal notifications:

- Assigned repairer on new request
- Finance on repairer endorsement
- Owner only when optional stage is active and pending
- Finance re-notified when owner final approval is done

## 9. Reliability and Governance Controls

- Idempotent execute endpoint to prevent duplicate payouts
- Immutable evidence snapshot for audit integrity
- Concurrency protection on final approval/execution transitions
- Overdue review escalation flags (repairer and finance)

## 10. Testing Strategy (design-level)

- Feature tests for full lifecycle with owner stage on/off
- Stage authorization tests per role
- Rejection paths at each stage
- Duplicate execute prevention/idempotency tests
- Customer timeline payload consistency tests

## 11. Rollout Strategy

Phase 1:

- Enable for online/myRepair refunds only
- Keep existing product order refund flow unchanged

Phase 2:

- Monitor SLA adherence, rejection distribution, execution failure rate

Phase 3 (optional):

- needs_more_evidence stage
- automated escalations
- enhanced risk scoring at finance stage

## 12. Non-Goals (v1)

- Return logistics workflow for repair services
- Auto-execute payouts without finance action
- Full dispute arbitration system

## 13. Success Criteria

- Repairer performs first technical gate consistently
- Finance executes only final-approved payouts
- Optional owner stage obeys settings policy
- Full stage-by-stage audit trail available for each refund request
- No duplicate payout incidents from repeated execution requests

## 14. Implementation Status

Status: Implemented

Completed endpoints:

- POST /api/customer/repairs/{id}/refunds -> requestRefundFromMyRepair
- GET /api/repairer/refunds -> repairerQueue
- POST /api/repairer/refunds/{refund}/approve -> repairerApprove
- POST /api/repairer/refunds/{refund}/reject -> repairerReject
- POST /api/finance/repair-refunds/{refund}/approve -> financeApprove
- POST /api/finance/repair-refunds/{refund}/reject -> financeReject
- POST /api/finance/repair-refunds/{refund}/execute -> financeExecute
- POST /api/shop-owner/repair-refunds/{refund}/approve -> ownerApprove
- POST /api/shop-owner/repair-refunds/{refund}/reject -> ownerReject

Test command log:

- php artisan test tests/Feature/RepairOnlineRefundWorkflowTest.php --filter=online_repair_refund_defaults_to_repairer_review_stage
- php artisan test tests/Feature/RepairOnlineRefundWorkflowTest.php --filter=repairer_can_endorse_refund_to_finance_with_assessment_note
- php artisan test tests/Feature/RepairOnlineRefundAuthorizationTest.php --filter=customer_refund_submission_enters_repairer_pending_stage
- php artisan test tests/Feature/RepairOnlineRefundWorkflowTest.php --filter=finance_cannot_approve_before_repairer_endorsement
- php artisan test tests/Feature/RepairOnlineRefundWorkflowTest.php --filter=full_online_refund_flow_repairer_finance_owner_optional_then_finance_execute
- php artisan test tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/RepairOnlineRefundAuthorizationTest.php
- pnpm vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.refund-workflow.test.tsx
