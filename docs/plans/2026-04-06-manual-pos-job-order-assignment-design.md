# Manual POS to Job Order Ownership Assignment Design

Date: 2026-04-06
Status: Approved
Scope: Convert manual walk-in POS repairs into owned Job Order workload with repairer assignment

## 1. Problem Statement

Manual walk-in repairs created from POS are currently managed in POS Manual Queue and excluded from Job Order workload.

Operational impact:
1. No guaranteed repairer ownership.
2. Material usage is not captured in Job Order workflow for these records.
3. Work execution and payment progression are split in ways that reduce accountability.

## 2. Approved Decisions

1. Manual walk-in repairs should become Job Order-owned records.
2. If POS actor is a repairer, assign to that same repairer.
3. If POS actor is not a repairer (Business type shop owner/cashier via RBAC), assign to least-loaded eligible repairer in the same shop.
4. If all repairers are over workload limit, still assign to least-loaded repairer (no POS checkout block).
5. Assigned manual POS records should appear in Job Orders.
6. Assigned manual POS records should not stay in POS Manual Queue.
7. Payments continue through Proceed to POS action from Job Order page.

## 3. Chosen Approach

Approach 1 (approved): Job-Order First Ownership with POS queue fallback only for non-owned records.

High-level behavior:
1. Keep manual POS creation path intact.
2. Add assignment resolution immediately after manual repair creation.
3. Persist assignment metadata (`assigned_repairer_id`, `assigned_at`, `assignment_method`).
4. Include assigned REP-POS records in workload APIs.
5. Exclude assigned REP-POS records from POS Manual Queue API.

## 4. Architecture and Data Flow

1. POS checkout (`/api/repair-pos/checkout`) creates manual repair request (`REP-POS-*`, `manual_pos_queue_enabled = true`).
2. Assignment resolver determines owner:
   a. Self-assign if actor has Repairer role.
   b. Else least-loaded repairer in same shop.
   c. Else over-limit fallback least-loaded repairer.
3. Manual repair is updated with owner fields and Job Order-compatible status (`assigned_to_repairer`).
4. Job Order page fetch (`/api/shop-owner/repairs` and `/api/repairer/repairs`) returns these assigned records.
5. POS manual queue list (`/api/repair-pos/manual-queue`) excludes assigned records.
6. Payment collection still uses Proceed to POS from Job Orders for deposit/balance/full dues.

## 5. Assignment Rules

1. Shop scope is mandatory: assignment candidates must match `shop_owner_id`.
2. Candidate eligibility:
   a. Active account.
   b. Repairer role (RBAC-conformant).
3. Strategy order:
   a. Self assign (repairer actor).
   b. Under-limit least-loaded repairer.
   c. Over-limit least-loaded repairer fallback.
4. Checkout must not fail solely due to over-limit conditions.

## 6. Visibility Rules

1. Job Orders include assigned manual walk-ins.
2. POS Manual Queue excludes records where `assigned_repairer_id` is present.
3. Unassigned/manual-failure records can remain visible in POS queue for operational recovery.

## 7. Error Handling and Integrity

1. If assignment fails due to transient issue:
   a. Keep checkout success.
   b. Record warning and assignment failure details in logs.
2. If no eligible repairer exists:
   a. Keep checkout success.
   b. Keep record unassigned and visible for manual recovery.
3. Enforce tenant boundaries to prevent cross-shop assignment.
4. Keep idempotent checkout behavior unchanged.

## 8. Testing Plan

### 8.1 Backend

1. Repairer actor checkout self-assigns owner.
2. Non-repairer actor checkout assigns least-loaded repairer.
3. Over-limit fallback still assigns a repairer.
4. Assigned manual record appears in workload endpoint.
5. Assigned manual record is excluded from manual queue endpoint.
6. Existing repair POS payment flow tests remain green.

### 8.2 Regression

1. Existing non-manual repair workflows are unaffected.
2. Existing Proceed to POS from Job Orders continues to work.

## 9. Rollout and Verification

1. Deploy backend and UI updates together.
2. Run targeted tests:
   a. `php artisan test --filter=RepairPosManualQueueTest`
   b. `php artisan test --filter=RepairPosPaymentFlowTest`
   c. relevant workload visibility tests.
3. Manual smoke:
   a. Create walk-in from POS.
   b. Confirm assignment to repairer.
   c. Confirm visibility in Job Orders.
   d. Confirm removal from POS Manual Queue.
   e. Continue payment through Proceed to POS.

## 10. Success Criteria

1. Every new manual walk-in has clear repair ownership (or explicit logged failure state).
2. Assigned manual walk-ins are handled through Job Orders for repair execution and materials tracking.
3. POS remains payment execution surface, not primary repair workflow tracker.
4. No checkout interruption when all repairers are above workload limit.
