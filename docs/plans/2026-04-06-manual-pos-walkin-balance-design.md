# Manual POS Walk-in Balance Continuation Design

Date: 2026-04-06
Status: Approved
Scope: Shop Owner POS manual walk-in lifecycle and 50/50 continuation flow

## 1. Problem Statement

Manual walk-in customers created from POS (non-account users) can pay initial deposit, but there is no dedicated queue to continue lifecycle status and collect remaining balance for `deposit_50` policy.

Recent hardening removed REP-POS records from Job Orders lists, which correctly separated workflows but also removed the only visible place staff used to progress those records.

## 2. Approved Decisions

1. Solution location: POS page only.
2. Manual lifecycle flow: `pending -> received -> in_progress -> ready_for_pickup -> picked_up`.
3. Balance collection timing: only when `ready_for_pickup`.
4. Queue lookup: receipt number OR phone/name.
5. Role access: all users with POS access.
6. Legacy behavior: old manual records are excluded; only new records participate.

## 3. Chosen Approach

Approach 1: POS-native Manual Queue with explicit marker field.

- Keep `repair_requests` as source of truth for workflow statuses.
- Add explicit queue marker for new manual POS records only.
- Keep REP-POS excluded from generic Job Orders list.
- Introduce dedicated repair-pos queue endpoints for read/search/status update.

Why this approach:
- Fastest safe implementation with low schema risk.
- Durable and auditable (not brittle pattern-only filtering).
- Avoids introducing a new subsystem/table for this phase.

## 4. Data Model

### 4.1 New field in repair_requests

Add boolean field:

- `manual_pos_queue_enabled` default `false`

Purpose:
- `true` means record is eligible for POS Manual Walk-in Queue.
- Existing records remain `false` and are naturally excluded.

### 4.2 Write behavior

In manual POS creation path (`RepairPosController::createManualRepairRequestFromPos`):

- Set `manual_pos_queue_enabled = true`.
- Do not expose this field from client payload.

## 5. API Design

## 5.1 List queue endpoint

`GET /api/repair-pos/manual-queue`

Filters:
- `manual_pos_queue_enabled = true`
- defensive: `request_id LIKE 'REP-POS-%'`
- status in `pending, received, in_progress, ready_for_pickup`

Search params (optional):
- `q` (matches receipt number, customer name, phone)

Response fields per row:
- repair id/request id
- customer name/phone
- status
- payment policy
- totals: final total, paid amount, refunded amount, remaining balance
- `next_due_type` (`deposit`, `balance`, `full`, or `null`)
- latest receipt number (if exists)

## 5.2 Update status endpoint

`PATCH /api/repair-pos/manual-queue/{repairId}/status`

Body:
- `status`

Allowed transitions:
- `pending -> received`
- `received -> in_progress`
- `in_progress -> ready_for_pickup`
- `ready_for_pickup -> picked_up`

Timestamp updates:
- `received_at`, `started_at`, `completed_at`, `picked_up_at`

Authorization:
- same POS-access actors under existing guards/policies.

Validation:
- reject invalid transitions with clear error message.

## 5.3 Continue payment

Continue using existing endpoint:

`POST /api/repair-pos/checkout`

Behavior:
- Queue row preloads selected manual record into POS payment pane.
- `due_type` resolution:
  - unpaid: `deposit`
  - partially paid and `ready_for_pickup`: `balance`
  - otherwise blocked

## 6. POS UI/UX

Target file:
- `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`

Add panel:
- **Manual Walk-in Queue** below/near current order picker.

Controls:
- search input (`receipt`, `name`, `phone`)
- refresh button

Per row actions:
- **Next Status** button (single valid next transition only)
- **Continue Payment** button

Continue payment button states:
- enabled for valid due phase
- disabled with tooltip/label when status or payment phase is not collectible

Empty state:
- "No manual walk-in records ready for queue."

## 7. Error Handling

1. Invalid status transition: return 422 with expected next status guidance.
2. Payment phase already settled: preserve existing `PAYMENT_PHASE_ALREADY_SETTLED` handling.
3. Search no results: explicit empty response and UI message.
4. Concurrency mismatch: refresh row and show warning toast.
5. Network/API errors: non-blocking toast with retry.

## 8. Security and Integrity

1. Queue marker is server-written only.
2. Transition graph enforced server-side.
3. Queue list restricted by shop scope and POS-access actor guards.
4. Audit logging for status and payment actions.

## 9. Backward Compatibility

1. Existing Job Orders flow remains unchanged for regular repairs.
2. Existing REP-POS exclusion from job-order lists remains in place.
3. Old manual records remain out of queue (by marker default false).

## 10. Validation Plan

Backend:
- queue list returns only marked records
- transition graph enforcement
- due_type gating for balance at `ready_for_pickup`
- receipt/name/phone search

Frontend:
- queue renders correctly
- action buttons state logic
- error message rendering for transition and payment conflicts

Regression:
- job-order list unaffected
- existing linked repair POS checkout unaffected

## 11. Success Criteria

1. Staff can update manual walk-in status from POS only.
2. Staff can collect remaining 50% when record reaches `ready_for_pickup`.
3. No old manual records appear in new queue.
4. Manual walk-in records stay out of generic Job Orders page.
