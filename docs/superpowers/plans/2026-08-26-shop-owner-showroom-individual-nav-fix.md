# Shop Owner Showroom and Individual Navigation Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve an active shop owner's configured 150-slot showroom capacity and restore the existing operational navigation for individual shop owners without changing company ERP navigation.

**Architecture:** The stored subscription/plan showroom limit is authoritative; legacy plan-code/name defaults are used only when no positive configured limit exists. Individual owners receive the existing direct operational routes in a separate sidebar section, filtered by the same business-type and module rules as those routes, while company owners continue using ERP Workspace.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Vitest, Tailwind CSS 4.

---

### Task 1: Lock the showroom-capacity regression

**Files:**
- Modify: `tests/Feature/PremiumFeatureTest.php` or the existing showroom/premium feature test selected after inspecting the test helpers.
- Test: the selected feature test for an active premium subscription with `showroom_slot_limit = 150` and a premium plan code/name.

- [x] **Step 1: Write the failing test**

Assert that the virtual-showroom Inertia payload preserves the stored 150-slot limit when both the subscription and its premium plan use a premium code/name.

- [x] **Step 2: Run the focused test to verify it fails**

Run the narrow PHPUnit test through the repository's existing Laravel test command. Expected result: the payload reports the legacy capped value instead of 150.

- [x] **Step 3: Implement the minimal controller change**

Make a positive configured plan/subscription limit take precedence over legacy code/name fallback mapping in `LandingPageController::resolveShowroomSlotLimit()`.

- [x] **Step 4: Run the focused test to verify it passes**

Run the same test and confirm the payload reports 150.

### Task 2: Lock the individual-owner navigation regression

**Files:**
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`.
- Test: individual retail/repair/both owner visibility for the existing product, job-order, repair, and logistics routes, while preserving company ERP Workspace behavior.

- [x] **Step 1: Write the failing tests**

Extend the current sidebar tests so an individual owner with the relevant module states can see the direct operational links and a company owner still does not receive those legacy direct links.

- [x] **Step 2: Run the focused frontend tests to verify they fail**

Run Vitest for `AppSidebar_shopOwner.test.tsx`. Expected result: the individual-owner operational-link assertions fail because the current sidebar only renders the reduced portal menu.

- [x] **Step 3: Implement the minimal sidebar change**

Restore the existing direct operational navigation for individual owners using existing route names, icons, business-type gates, and module-access checks. Keep company owners on ERP Workspace and preserve mobile/sidebar collapse behavior.

- [x] **Step 4: Run the focused frontend tests to verify they pass**

Run the same Vitest file and confirm both individual operational links and company ERP-only behavior.

### Task 3: Verify the integrated fix

**Files:**
- Modify only files required by Tasks 1–2 and their focused tests.

- [x] **Step 1: Run diff hygiene and static checks**

Run `git diff --check`, PHP lint for changed PHP files, and the focused frontend test command.

- [x] **Step 2: Run the frontend build**

Run the repository build command when dependencies are available; do not commit generated `public/build` output unless explicitly requested.

- [x] **Step 3: Review the final diff**

Confirm the branch is based on `origin/solespace-b`, unrelated worktrees were not touched, company navigation remains unchanged, and only the two reported regressions are addressed.
