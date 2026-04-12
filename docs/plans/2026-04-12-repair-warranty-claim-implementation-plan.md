# Repair Warranty Claim Implementation Plan

> For agentic workers: execute task-by-task with test-first updates and small commits.

**Goal:** Implement Repair Warranty with linked no-charge rework jobs, one-claim limit, same-issue validation, required evidence, and individual repair-owner accountability.

**Architecture:** Extend existing repair workflow with a new warranty claim table and linked warranty job creation path; keep original repair records immutable.

**Tech Stack:** Laravel 11 (PHP), MySQL migrations, Eloquent models/policies/services, Inertia React + TypeScript pages, PHPUnit and Vitest.

---

## File Structure and Responsibilities

- Create: app/Models/RepairWarrantyClaim.php
  - Warranty claim aggregate with statuses and relationships.
- Create: database/migrations/2026_04_12_000001_create_repair_warranty_claims_table.php
  - New warranty claim table.
- Create: database/migrations/2026_04_12_000002_add_warranty_fields_to_repair_requests_and_shop_owners.php
  - Add shop policy and repair linkage fields.
- Modify: app/Models/RepairRequest.php
  - Add fillable/casts/relations for warranty linkage and individual repair-owner ownership.
- Modify: app/Models/ShopOwner.php
  - Add warranty policy fields.
- Create: app/Http/Controllers/Api/RepairWarrantyClaimController.php
  - Customer filing and read endpoints.
- Create: app/Http/Controllers/Api/RepairerWarrantyClaimController.php
  - Repairer queue and approve/reject endpoints.
- Create: app/Services/RepairWarrantyService.php
  - Eligibility checks, approve transaction, linked job creation.
- Modify: routes/web.php
  - Register customer and repairer warranty claim routes under existing API groups.
- Modify: app/Services/NotificationService.php
  - Add warranty claim notification fan-out including individual repair-owner.
- Modify: resources/js/Pages/UserSide/Repairs/myRepairs.tsx
  - Add customer claim CTA and modal flow.
- Modify: resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
  - Add warranty claim review queue and actions.
- Create: tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php
  - End-to-end backend flow tests.
- Create: tests/Feature/Repair/Warranty/RepairWarrantyEligibilityTest.php
  - Eligibility edge cases.
- Create: resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.warranty-claim.test.tsx
  - Customer UI claim tests.
- Create: resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.warranty-queue.test.tsx
  - Repairer queue tests.

---

## Task 1: Schema and Model Foundations

- [ ] Add migration for repair_warranty_claims table.
- [ ] Add migration for:
  - shop_owners.repair_warranty_days
  - shop_owners.warranty_enabled
  - repair_requests.is_warranty_job
  - repair_requests.parent_repair_request_id
  - repair_requests.warranty_sequence
  - repair_requests.warranty_claim_id
  - repair_requests.billing_mode
  - repair_requests.warranty_display_alias
  - repair_requests.individual_repair_owner_user_id
- [ ] Add indexes and unique guard for one approved claim per original repair.
- [ ] Add RepairWarrantyClaim model with relationships to RepairRequest/User/ShopOwner.
- [ ] Extend RepairRequest and ShopOwner models (fillable, casts, relations).
- [ ] Run migrations and baseline tests.

Commit message:
- feat(repair): add warranty claim schema and model foundations

## Task 2: Eligibility Service and Customer Filing API

- [ ] Add RepairWarrantyService::validateEligibility(originalRepair, user).
- [ ] Enforce:
  - warranty enabled,
  - within warranty window,
  - same issue confirmation,
  - required photo evidence,
  - no prior approved claim.
- [ ] Add customer endpoint POST /api/customer/repairs/{id}/warranty-claims.
- [ ] Add endpoint to fetch latest claim status per repair.
- [ ] Add feature tests for pass/fail eligibility matrix.

Commit message:
- feat(repair): implement customer warranty claim filing and eligibility checks

## Task 3: Repairer Review and Linked Job Creation

- [ ] Add repairer queue endpoint GET /api/repairer/warranty-claims.
- [ ] Add approve endpoint with transactional linked-job creation.
- [ ] Ensure linked warranty job fields:
  - is_warranty_job true,
  - parent_repair_request_id set,
  - billing_mode warranty_no_charge,
  - total/final_total forced to 0,
  - payment_enabled false,
  - warranty alias with W1 display convention,
  - individual_repair_owner_user_id copied.
- [ ] Add reject endpoint with reason persistence.
- [ ] Add backend tests:
  - exactly one linked job on approve,
  - no linked job on reject,
  - parent immutability.

Commit message:
- feat(repair): add repairer warranty review and linked rework job creation

## Task 4: Notification and Accountability Fan-out

- [ ] Add notification events for claim filed/approved/rejected/completed.
- [ ] Ensure recipients include:
  - customer,
  - repairer,
  - manager,
  - shop owner,
  - individual repair-owner.
- [ ] Add audit log entries with actor, decision, and timestamps.
- [ ] Add tests for recipient fan-out including individual repair-owner.

Commit message:
- feat(repair): wire warranty claim notifications and accountability audit trail

## Task 5: Customer UI Integration

- [ ] In myRepairs UI add File Warranty Claim button on card and detail views.
- [ ] Add modal fields:
  - reason,
  - same issue confirmation,
  - required image upload,
  - return method selection.
- [ ] Show disabled reason states and latest claim status chips.
- [ ] Add vitest coverage for visibility, validation, and submit behavior.

Commit message:
- feat(repair-ui): add customer warranty claim entry points and validation UX

## Task 6: Repairer UI Queue Integration

- [ ] Add Warranty Claims queue section in repairer Job Orders page.
- [ ] Show parent reference, evidence previews, expiry snapshot, and individual repair-owner.
- [ ] Add approve/reject actions with loading and error states.
- [ ] Add vitest coverage for queue filtering and decision actions.

Commit message:
- feat(repair-ui): add repairer warranty claims review queue

## Task 7: KPI and Reporting Hooks

- [ ] Add service/query support for:
  - claim count,
  - approval rate,
  - repeat issue rate by service/package,
  - resolution duration,
  - breakdown by individual repair-owner.
- [ ] Expose summary endpoint or payload extension for ERP dashboards.
- [ ] Add tests for metric calculations.

Commit message:
- feat(repair-analytics): add warranty KPI reporting with owner breakdown

## Task 8: Regression and Release Gate

- [ ] Run targeted tests:
  - php artisan test tests/Feature/Repair/Warranty
  - vitest warranty-related tests
- [ ] Run existing repair flow tests to ensure no regressions.
- [ ] Verify migration up/down on local DB snapshot.
- [ ] Validate no route collisions and authorization leaks.

Release gate checklist:
- [ ] One approved claim max enforced.
- [ ] Zero-charge warranty billing immutable.
- [ ] Original repair job remains unchanged.
- [ ] Individual repair-owner appears in notifications and reporting.
- [ ] Customer and repairer UI paths are fully functional.

---

## Rollback Strategy

1. Feature-flag warranty claim endpoints and UI actions.
2. Disable claim submission while preserving existing repair lifecycle.
3. Keep migrations additive and non-destructive for safe rollback.

## Out of Scope for This Plan

1. Multi-claim per original repair.
2. Partial-charge warranty labor/material split.
3. Cross-shop warranty transfer logic.
