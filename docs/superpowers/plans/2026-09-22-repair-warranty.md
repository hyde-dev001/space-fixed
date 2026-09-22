# Repair Warranty Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a shop-configurable repair warranty whose terms are snapshotted at customer handover (`picked_up_at`) and exposed consistently to the customer claim flow.

**Architecture:** Reuse the existing `ShopOwner` warranty toggle, `repair_warranty_days` setting, `RepairWarrantyService`, claim controllers, and customer My Repairs page. Add a DDL migration for the duration unit and immutable per-repair warranty snapshot plus a separate one-time data backfill, then make the service the single source of eligibility and claimability truth. Existing warranties remain usable from their stored snapshot after settings change or disablement.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Vite 7, PHPUnit/Pest-style Laravel feature tests.

---

### Task 1: Add failing warranty configuration and snapshot tests

**Files:**
- Create: `tests/Feature/ShopOwner/RepairWarrantySettingsTest.php`
- Create: `tests/Feature/Repair/Warranty/RepairWarrantySnapshotTest.php`
- Reference: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Reference: `app/Services/RepairWarrantyService.php`

- [x] **Step 1: Write failing tests**
  - Verify a shop owner can save enabled warranty duration and unit through the existing settings endpoint.
  - Verify enabled duration limits are enforced per unit: days ≤365, weeks ≤52, months ≤12.
  - Verify handover issuance stores start, expiry, duration, unit, and issued flag.
  - Verify disabled warranty does not issue a snapshot.
  - Verify a stored snapshot remains unchanged after shop settings change.
  - Verify claim eligibility rejects a picked-up repair without an issued snapshot and an expired snapshot.
- [x] **Step 2: Run only the new tests and confirm they fail for the expected missing behavior.**

### Task 2: Add the additive schema and model fields

**Files:**
- Create: `database/migrations/2026_09_22_000003_add_repair_warranty_snapshot_fields.php`
- Create: `database/migrations/2026_09_22_000004_backfill_repair_warranty_snapshots.php`
- Modify: `app/Models/ShopOwner.php`
- Modify: `app/Models/RepairRequest.php`

- [x] **Step 1: Add `shop_owners.repair_warranty_duration_unit` with a `days` default and immutable repair snapshot columns: issued flag, started/expired timestamps, duration, and unit.**
- [x] **Step 2: Backfill only legacy handed-over repairs with a currently enabled warranty, using each shop’s existing settings and `picked_up_at`; never overwrite existing snapshots.**
- [x] **Step 3: Add model fillable/cast/default metadata and run the migration-focused tests.**

### Task 3: Make the warranty service snapshot-driven

**Files:**
- Modify: `app/Services/RepairWarrantyService.php`

- [x] **Step 1: Add safe unit/duration normalization and expiry calculation for days, weeks, and months.**
- [x] **Step 2: Add idempotent `issueAtHandover()` that reads the current shop setting once and stores the snapshot only when warranty is enabled.**
- [x] **Step 3: Add centralized `hasWarranty`, active-state, and `canClaim` payload helpers based on the snapshot and existing claim rules.**
- [x] **Step 4: Change `validateEligibility()` and claim creation/approval checks to use the issued snapshot rather than current settings.**
- [x] **Step 5: Run the warranty test suite and fix only regressions caused by the new contract.**

### Task 4: Issue warranties at registered and manual-POS handover

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`

- [x] **Step 1: Call `issueAtHandover()` after a successful registered-customer `confirmPickup()` update.**
- [x] **Step 2: Call it after a successful manual-POS transition to `picked_up`, including the no-account walk-in handoff path.**
- [x] **Step 3: Do not issue at `completed` or `ready_for_pickup`; preserve the agreed customer handover start.**
- [x] **Step 4: Run the focused handover and warranty-flow tests.**

### Task 5: Expose warranty state to customer repairs

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`

- [x] **Step 1: Include a nullable warranty payload in My Repairs and repair detail responses only when an issued snapshot exists.**
- [x] **Step 2: Show active warranty dates/status on the customer repair card/detail, show expired as non-actionable, and hide warranty UI when no snapshot exists.**
- [x] **Step 3: Render the claim action only from backend `can_claim`; retain the existing claim modal and SweetAlert behavior.**
- [x] **Step 4: Add/update a focused frontend contract test for the warranty fields and claim visibility.**

### Task 6: Add non-technical shop settings controls

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`

- [x] **Step 1: Return warranty enabled, duration, and duration unit from the existing settings page payload.**
- [x] **Step 2: Validate and persist settings through the existing `PUT /shop-owner/settings` endpoint without requiring warranty fields on unrelated saves.**
- [x] **Step 3: Add an accessible Repair Warranty panel with a toggle, numeric duration, unit select, clear limits, disabled controls when off, and existing save/error feedback patterns.**
- [x] **Step 4: Run frontend tests and build.**

### Task 7: Review and verify

**Files:**
- Modify only if required by review: files above.

- [x] **Step 1: Run sequential standards, spec, simplification, TypeScript/React, security, and dead-code reviews.**
- [x] **Step 2: Run `git diff --check`, focused Laravel tests, the focused frontend test, and the production build.**
- [x] **Step 3: Confirm the two pre-existing rider files remain untouched by this work.**
- [x] **Step 4: No new durable implementation lesson was required.**
