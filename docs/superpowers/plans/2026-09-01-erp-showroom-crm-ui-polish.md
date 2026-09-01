# ERP, Showroom, and CRM UI Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the requested portrait, approval-dialog, navbar-layer, and CRM detail-modal UI corrections without changing application data flows.

**Architecture:** Reuse the existing Tailwind utility classes and `workflowFeedback` SweetAlert2 helper. Keep the showroom controls as separate overlays, use one bounded ERP chrome layer with higher modal layers, and remove only the CRM modal's visible edit trigger.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, SweetAlert2, Vite 7.

## Global Constraints

- Preserve unrelated working-tree changes.
- Do not change ERP routes, API requests, authorization, database behavior, or dependencies.
- Use the existing `workflowFeedback.confirm` SweetAlert2 path for Manager approval.
- Generate and include a fresh `public/build` after the final rebase.
- Push only `feature/monochrome-erp-theme-clean`; do not create a PR.

---

### Task 1: Add regression contracts

**Files:**
- Modify: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts`
- Modify: `resources/js/Pages/ERP/Manager/__tests__/SuspensionApprovals.contract.test.ts`
- Modify: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`
- Create: `resources/js/Pages/ERP/CRM/__tests__/Customers.contract.test.ts`

**Interfaces:**
- Consumes the current JSX source and rendered header classes.
- Produces focused checks for the responsive controls, bounded modal/header layers,
  existing SweetAlert confirmation path, and absence of the CRM edit label.

- [x] **Step 1: Add the showroom portrait contract**

  Assert that the page back control uses the phone-safe `left-3 top-3` position
  and that the standalone Night/Day control uses `left-3 top-16` with the
  desktop `sm:right-3 sm:top-3` override.

- [x] **Step 2: Add the ERP layer and feedback contract**

  Assert that the header uses `z-40`, the application menu uses `z-50`, both
  Suspension Approvals dialogs use `z-[100]`, and approval continues to use
  `workflowFeedback.confirm` without native dialog calls.

- [x] **Step 3: Add the CRM action contract**

  Read `Customers.tsx` and assert that its customer detail modal no longer
  contains the `Edit Customer` label while retaining the `Close` action.

- [x] **Step 4: Run the focused contracts and confirm the expected red failures**

  Run:

  ```powershell
  .\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts resources/js/Pages/ERP/Manager/__tests__/SuspensionApprovals.contract.test.ts resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/CRM/__tests__/Customers.contract.test.ts
  ```

  Expected: the new assertions fail because the current classes/action are still
  present; no test failure should be caused by a syntax or import error.

### Task 2: Implement the isolated UI fixes

**Files:**
- Modify: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx`
- Modify: `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx`
- Modify: `resources/js/layout/AppHeader_ERP.tsx`
- Modify: `resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx`
- Modify: `resources/js/Pages/ERP/CRM/Customers.tsx`

**Interfaces:**
- Keeps existing `VirtualShowroom`, `workflowFeedback`, and CRM API interfaces.
- Produces no new public API or state contract.

- [x] **Step 1: Stack standalone controls below `sm`**

  Move the page back link to `left-3 top-3` and make the showroom Night/Day
  control `left-3 top-16` on narrow screens, with its current right/top desktop
  placement restored from `sm` upward. Keep the controls pointer-enabled and
  give them enough visual spacing to avoid covering the centered room status.

- [x] **Step 2: Bound ERP layers**

  Change the ERP sticky header to `z-40`, remove the redundant extreme z-index
  from the header toggle, set the compact application menu to `z-50`, and use
  `z-[100]` for both Suspension Approvals dialogs. Leave
  `workflowFeedback.confirm` and the approve/re-fetch sequence unchanged.

- [x] **Step 3: Remove only the CRM edit trigger**

  Remove the `Edit Customer` button branch from the detail modal header and
  keep the close button and all detail tabs intact. Do not remove or alter the
  customer API functions used by other existing behavior.

- [x] **Step 4: Run the focused contracts green**

  Re-run the Task 1 command. Expected: all focused tests pass.

### Task 3: Review and release

**Files:**
- Inspect: all changed source/tests and `public/build`
- Modify: `public/build` via the Vite build only

- [x] **Step 1: Run frontend verification**

  Run `.\node_modules\.bin\vitest.cmd run` and record the test count and exit
  code. No TypeScript or lint pass is claimed because the repository does not
  provide committed TypeScript/lint tooling.

- [x] **Step 2: Run the Laravel and hygiene checks**

  Run `php artisan test tests/Feature/Logistics` and `git diff --check`.

- [x] **Step 3: Rebase on the shared branch before building**

  Run `git fetch origin --prune` and `git rebase origin/solespace-b`, preserving
  the user's unrelated worktree files and resolving only clear conflicts.

- [x] **Step 4: Build the final revision once**

  Run `.\node_modules\.bin\vite.cmd build`, inspect the generated output, and
  stage the full fresh `public/build` together with only intended source,
  test, spec, and plan files.

- [ ] **Step 5: Commit and push the feature branch**

  Run `git diff --cached --check`, commit with
  `fix: polish responsive erp approval and crm ui`, push
  `feature/monochrome-erp-theme-clean`, and verify the remote branch SHA. Do
  not create a PR.

## Recorded verification

- Focused contracts: 4 files passed, 15 tests passed.
- Full frontend suite: 171 files passed, 928 tests passed via the installed
  Vitest binary. The documented `pnpm` PowerShell wrapper is blocked by local
  execution policy, and `pnpm.cmd` stalled without output.
- Laravel Logistics suite: 385 passed, 1 skipped, 1,984 assertions.
- Final isolated build: `vite.cmd build` passed after unrelated user files were
  temporarily isolated; the resulting `public/build` is staged.
- Browser smoke test: the local showroom route returned its expected `403`
  premium-subscription guard, so authenticated showroom rendering could not be
  exercised in the seeded local state.
