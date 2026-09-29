# Customer dispute UI, proof preview, and investigation gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Limit new dispute resolutions to supported workflows, require investigation before resolution, and let staff preview delivery proof images from Job Orders.

**Architecture:** Preserve the existing resolution enum and refund request flow for compatibility. Enforce the investigation prerequisite in `DeliveryDisputeService`, mirror it in the Shipment UI, and reuse the existing proof URL plus modal interaction pattern in Job Orders.

**Tech Stack:** Laravel 12/PHP 8.2, PHPUnit, React 18, TypeScript, Inertia, Vitest, Tailwind, Vite.

---

## Task 1: Add failing regression coverage

- [x] Add a feature test proving an open dispute cannot be resolved directly.
- [x] Add Shipment UI coverage for the reduced and reason-conditioned resolution choices and investigation gate.
- [x] Add Job Orders UI coverage for opening delivery proof in a modal.
- [x] Run the focused tests and confirm they fail for the current implementation.

## Task 2: Implement the minimal behavior changes

- [x] Restrict service resolution to disputes in `investigating` state.
- [x] Hide unsupported resolution choices while retaining legacy backend values.
- [x] Allow `customer_confirmed` only for `item_not_received` in both the Shipment UI and service validation.
- [x] Allow same-shop Job Orders staff to fetch retail delivery proof files without granting access to unrelated logistics proofs.
- [x] Show Resolve only after Start investigation is completed.
- [x] Convert Staff Job Orders proof links into accessible modal triggers and add the proof preview modal.
- [x] Keep the Job Orders proof preview above the Order Details modal when both are open.

## Task 3: Verify, review, and prepare the branch

- [x] Run focused backend/frontend tests, PHP syntax checks, `git diff --check`, and the frontend build.
- [x] Review the diff for compatibility, authorization/data-integrity regressions, dead code, and unnecessary complexity.
- [x] Include the generated `public/build` output requested for the deployed preview.
- [x] Commit and push the feature branch for PR creation.

## Follow-up: Complete Staff Order Details shipping coverage

- [x] Read shipment summary fields from `logistics` for the Staff Order Details Shipping & Tracking section.
- [x] Preserve legacy order-level shipping fields as a fallback for third-party logistics records.
- [x] Add regression coverage for complete shop-owned shipment and tracking details.
- [x] Scope existing logistics assertions to their sections after the new summary intentionally repeats shipment values.

## Follow-up: Remove duplicate shipping summary

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- Update: `docs/superpowers/specs/2026-08-22-customer-dispute-ui-proof-gate-design.md`
- Build: `public/build`

- [x] Update the regression test to require ETA inside the existing `Customer delivery` region and reject a separate `Shipping & Tracking` region.
- [x] Run the focused test to confirm the current duplicate layout fails the new expectation.
- [x] Add ETA to the existing customer-delivery logistics rows and remove the duplicate summary block/state.
- [x] Preserve legacy third-party shipping fields through the same customer-delivery region.
- [x] Run Staff Job Orders and Logistics Shipments tests.
- [x] Build and commit the updated frontend assets and documentation.
- [x] Push the feature branch and verify the remote commit.
