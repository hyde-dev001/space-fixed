# Repair Warranty Claim Design

## Context

Panel feedback highlighted a missing after-service quality loop for repairs: if the customer is not satisfied and the issue reoccurs, they should be able to return the item for no-charge rework ("back job") within a controlled period.

This design introduces a formal Repair Warranty system with Warranty Claim flow, while preserving immutable history on original repair jobs.

## Approved Decisions

1. Warranty period source: per shop owner setting.
2. Warranty counting start: customer confirmed pickup/received timestamp.
3. Maximum claims per original repair: one approved claim.
4. Valid scope: same issue only.
5. Review order: repairer-first decision.
6. Evidence requirement: reason plus at least one photo.
7. Approval result: create a linked new repair job (do not edit original job).
8. Warranty job identity: normal generated request ID plus display alias suffix (for example W1).
9. Billing policy: warranty jobs are always zero-charge and non-editable.
10. Return method: walk-in or delivery-to-shop selectable by customer.
11. Shipping cost policy: customer shoulders shipping.
12. Customer UI entry points: repair card action and repair detail action.
13. Notifications follow registration-type routing: individual registration routes operational notifications to the owner-as-handler; business registration routes operational notifications to the assigned repair employee and keeps owner oversight notifications.
14. KPIs: claim count, approval rate, repeat issue rate by service/package, and resolution time.
15. Accountability model: registration type determines the operational handler (individual owner for individual shops, assigned employee for business shops).

## Goals

1. Provide a formal no-charge rework path for eligible repairs.
2. Preserve auditability by keeping original repair jobs immutable.
3. Reuse existing repair workflow where possible to minimize risk.
4. Add person-level accountability through registration-type-aware handler ownership and visibility.
5. Enable measurable service quality outcomes via warranty KPIs.

## Non-Goals

1. Redesigning the entire repair lifecycle.
2. Adding financial refund logic to warranty flow.
3. Introducing unlimited claims or cross-issue coverage.

## Approach Options

### Option A: Hybrid Linked-Job Model (Recommended)

- Add warranty claim records.
- On approval, create a new linked warranty repair job with zero billing.
- Keep original job unchanged.

Pros:
- Strong audit trail.
- Reuses existing repair execution flow.
- Best fit for current architecture.

Cons:
- Moderate schema additions.

### Option B: Separate Warranty Engine

- Build fully separate warranty job domain and state machine.

Pros:
- Clean strict separation.

Cons:
- High implementation cost.
- Duplicates existing workflow logic.

### Option C: Original Job Reopen Overlay

- Reopen original repair job on approved claim.

Pros:
- Fastest implementation.

Cons:
- Weak audit integrity.
- Conflicts with panel requirement for immutable original records.

## Target Architecture

### Core Entities

1. Repair Request (existing): stores both regular and warranty execution jobs.
2. Repair Warranty Claim (new): customer-filed claim with evidence, eligibility snapshot, and decision record.
3. Shop Owner Warranty Policy (new/extended setting): controls warranty period per shop.

### New/Extended Fields

Repair request extensions:
- is_warranty_job (boolean)
- parent_repair_request_id (nullable self reference)
- warranty_sequence (nullable integer)
- warranty_claim_id (nullable FK)
- billing_mode (enum/string: warranty_no_charge for warranty jobs)
- warranty_display_alias (nullable string)
- repair_handler_user_id (nullable FK; owner-linked user for individual registration, assigned employee user for business registration)
- handler_source (enum/string: individual_owner or business_employee)

Shop owner settings:
- repair_warranty_days (integer, min 1, max 90)
- warranty_enabled (boolean)

Warranty claim record:
- claim_no
- original_repair_request_id
- approved_repair_request_id (nullable)
- customer_user_id
- shop_owner_id
- repair_handler_user_id (snapshot)
- handler_source (snapshot)
- status (pending_repairer, approved, rejected, expired)
- reason_code
- reason_details
- same_issue_confirmation (boolean)
- evidence_media (json array)
- preferred_return_method (walk_in, customer_delivery)
- shipping_cost_bearer (customer)
- warranty_started_at_snapshot
- warranty_expires_at_snapshot
- reviewed_by_repairer_id
- reviewed_at
- rejection_reason

## Workflow Design

### 1. Customer Files Warranty Claim

Entry points:
- My Repairs card action: File Warranty Claim
- Repair detail action: File Warranty Claim
- POS counter-assisted claim for manual walk-in repairs (submitted by cashier/repair staff)

Submit payload:
- reason + details
- same issue confirmation
- at least one image
- return method (walk-in or delivery-to-shop)

Manual POS walk-in payload additions:
- request_id or repair_request_id
- presented_receipt_no
- walk_in_phone

Manual POS walk-in identity rule:
- since manual walk-in jobs have no customer user account, warranty claims are filed by authorized shop actor at the counter,
- claim requires matching receipt number and walk-in contact details to the linked repair job before creation.

### 2. Eligibility Validation

Eligibility passes only if:
1. Parent repair is in completed customer-closed state (picked up/received confirmation).
2. Current time is within warranty window.
3. No existing approved warranty claim for the parent repair.
4. Same-issue confirmation is true.
5. Evidence requirements are satisfied.

Additional checks for manual walk-in POS repairs:
6. Presented receipt matches an existing repair POS transaction for the same repair request.
7. Walk-in contact details (phone) match the stored repair/POS record.

### 3. Repairer Review

Queue actor depends on registration type:
- individual registration: owner-as-handler (owner-linked user)
- business registration: assigned repair employee

Shared actions:
- Approve: creates linked warranty repair job.
- Reject: closes claim with reason.

Business owner oversight:
- receives visibility notifications for governance and escalation,
- can monitor and annotate,
- acts as accountability owner for business-level audit and KPI reporting.

### 4. Approved Claim Job Creation

Transactional create of linked warranty job:
- copy relevant snapshots from parent repair,
- set is_warranty_job true,
- set parent_repair_request_id,
- set billing_mode warranty_no_charge,
- force total/final_total to 0,
- set payment_enabled false,
- create display alias suffix (W1),
- set and copy repair_handler_user_id and handler_source using parent registration type.

Original repair remains immutable.

### 5. Rejected Claim

- Persist decision and reason.
- No linked job is created.
- Notify all involved roles.

## API Contract (Proposed)

Customer:
- POST /api/customer/repairs/{id}/warranty-claims
- GET /api/customer/repairs/{id}/warranty-claims/latest

POS walk-in (counter-assisted):
- POST /api/repair-pos/warranty-claims
  - actor-authenticated shop endpoint for manual walk-in repairs without customer login
  - validates receipt_no plus walk-in contact match before claim creation

Repairer:
- GET /api/repairer/warranty-claims
- POST /api/repairer/warranty-claims/{claim}/approve
- POST /api/repairer/warranty-claims/{claim}/reject

Staff/owner visibility (optional same phase or next phase):
- GET /api/workflow/repairs/warranty-claims

## UI/UX Design

### Customer

- Show claim button only when eligible.
- Disabled state with clear reason:
  - expired,
  - warranty already used,
  - not yet eligible status.
- Claim modal enforces required image evidence.
- Show status chips and timeline entries for claim decisions.

### POS Manual Walk-in

- Add File Warranty Claim action in repair POS history/manual queue for REP-POS jobs.
- Counter form captures:
  - presented receipt number,
  - walk-in phone,
  - reason,
  - same issue confirmation,
  - photo evidence.
- Frontend blocks submission if receipt or contact verification fails.
- On success, claim appears in same warranty review queue with source tag: manual_pos_walk_in.

### Repairer and ERP

- Add Warranty Claims queue with filtering.
- Show parent repair reference, warranty expiry snapshot, evidence preview, and handler tag (individual owner or business employee).
- Show intake source tag (customer_portal or manual_pos_walk_in) to distinguish manual walk-in claims.
- Warranty jobs display explicit badges:
  - Warranty Rework
  - No Charge

## Notification Matrix

On claim filed:
- customer,
- handler (owner-as-handler for individual registration, assigned employee for business registration),
- manager,
- shop owner.

On claim approved:
- customer,
- handler (owner-as-handler for individual registration, assigned employee for business registration),
- manager,
- shop owner.

On claim rejected:
- customer,
- handler (owner-as-handler for individual registration, assigned employee for business registration),
- manager,
- shop owner.

On warranty job completed:
- customer,
- handler (owner-as-handler for individual registration, assigned employee for business registration),
- shop owner.

## Business Controls and Auditability

1. Hard one-approved-claim limit per parent repair.
2. Immutable original job policy.
3. Non-editable zero-billing warranty jobs.
4. Structured activity logs for claim lifecycle and linked job creation.
5. Concurrency guard on approve path to prevent duplicate linked jobs.

## Testing Strategy

### Backend

1. Eligibility checks: in-window, expired, missing evidence, already-used warranty.
2. Approval path: linked job creation, zero billing enforcement, parent immutability.
3. Rejection path: no linked job, reason persistence.
4. Notification fan-out follows registration-type handler routing.

### Frontend

1. Claim button visibility and disabled states.
2. Required image validation.
3. Repairer queue approve/reject behavior.
4. Warranty badges and parent-link rendering.

## Metrics and Reporting

1. Warranty claims per month.
2. Approval rate.
3. Repeat issue rate by service/package.
4. Average claim-to-resolution duration.
5. Breakdown by registration type and handler (individual owner or business employee).

## Rollout Plan (High Level)

1. Phase 1: schema + backend claim APIs + eligibility checks.
2. Phase 2: customer claim UI + repairer review queue.
3. Phase 3: notification matrix and registration-type handler accountability reporting.
4. Phase 4: KPI dashboards and policy tuning.

## Acceptance Criteria

1. Customer can submit one warranty claim within shop-defined period for same issue with required evidence.
2. Approved claim creates exactly one linked no-charge warranty repair job.
3. Original repair job is not modified for rework tracking.
4. Registration-type handler routing is enforced: individual owner handles individual shops, assigned employee handles business shops, with owner oversight in notifications and KPI slices.
5. End-to-end tests cover happy path and rejection/expiry edge cases.
