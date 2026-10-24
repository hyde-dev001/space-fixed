# Repair Return Live Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing customer live-tracking pipeline so shop-owned `repair_return` riders appear on the customer tracking map.

**Architecture:** Reuse `RiderLocationService` as the shared backend authorization and payload boundary. Extend `ShipmentTrackingPanel` predicates so its current five-second polling and `LiveTrackingMap` support repair returns without adding routes or storage. Keep the feature flag and current privacy safeguards unchanged.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript, Vitest, PHPUnit.

---

### Task 1: Add failing backend coverage

**Files:**
- Modify: `tests/Feature/Logistics/RiderLocationApiTest.php`

- [x] **Step 1: Write the failing repair-return location test.**
  Reuse the existing rider, shop, customer, shipment, assignment, and location fixtures. Create a `repair_return` outbound leg with a shop origin and customer destination, post a GPS update as the assigned rider, then assert the owning customer's tracking payload contains `live_tracking.location` and the customer destination.

- [x] **Step 2: Run the focused tests and verify the expected failure.**
  Run:
  `php artisan test tests/Feature/Logistics/RiderLocationApiTest.php tests/Feature/Logistics/CustomerTrackingTest.php`
  Expected: the new repair-return assertions fail because the shared trackability predicate currently excludes `repair_return`.

### Task 2: Extend the shared backend tracking rules

**Files:**
- Modify: `app/Services/Logistics/RiderLocationService.php`

- [x] **Step 1: Add repair-return phase recognition.**
  Recognize an outbound `repair_return` leg before handoff when it is assigned/scheduled at a shop origin, and after handoff while it is in transit to a customer destination.

- [x] **Step 2: Select the correct tracking target.**
  Use the shop origin before handoff and the customer destination after `picked_up_at`, preserving the existing route and stale-location payload shape.

- [x] **Step 3: Run the focused backend tests and verify they pass.**
  Run the same command from Task 1. Expected: all existing and new logistics tests pass.

### Task 3: Add failing frontend coverage

**Files:**
- Modify: `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTrackingLive.test.tsx`

- [x] **Step 1: Add a repair-return live tracking fixture and assertion.**
  Assert the tracking panel treats an active `repair_return` outbound leg as a live return phase and requests the tracking endpoint for updates.

- [x] **Step 2: Run the focused Vitest test and verify the expected failure.**
  Run:
  `node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTrackingLive.test.tsx`
  Expected: the new repair-return assertion fails because the UI predicate currently only recognizes repair pickup and retail refund return.

### Task 4: Extend the tracking panel minimally

**Files:**
- Modify: `resources/js/components/logistics/ShipmentTrackingPanel.tsx`

- [x] **Step 1: Add repair-return phase predicates.**
  Match the backend's before-handoff, in-transit, and handoff-pending states.

- [x] **Step 2: Reuse existing live map, labels, polling, and stale/retry states.**
  Add only the return-specific label/map target selection needed for the existing component.

- [x] **Step 3: Run the focused Vitest test and verify it passes.**
  Run the Task 3 command. Expected: all live-tracking tests pass.

### Task 5: Review and verify

**Files:**
- Review all changed files and their tests.

- [x] **Step 1: Perform standards, spec, simplification, TypeScript/React, security, and reuse/dead-code reviews.**
- [x] **Step 2: Run focused backend and frontend tests.**
- [x] **Step 3: Run `git diff --check`.**
- [x] **Step 4: Run `pnpm run build` or the repository's equivalent Vite build and record any pre-existing warning.**
- [x] **Step 5: Confirm `.env`, `vendor/`, `node_modules/`, and unrelated `storage/framework/cache/` remain untouched.**
