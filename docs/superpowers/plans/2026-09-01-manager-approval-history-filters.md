# Manager Approval History Filters Implementation Plan

> **For agentic workers:** Execute this plan task-by-task with focused verification after each task.

**Goal:** Let Managers browse filtered termination and rehire approval history while simplifying the Suspension approval summary presentation.

**Architecture:** Reuse the existing lifecycle approval endpoints and their `status`/`search` query contract. Add local draft filters and applied query state to the shared termination/rehire page, without adding statuses or client-side data filtering. Keep Suspension request status badges intact while making its summary metrics neutral and removing the redundant latest-request card.

**Tech Stack:** Inertia/React 18, TypeScript, Tailwind CSS, Vitest.

---

### Task 1: Lock the missing behaviors with regression contracts

**Files:**
- Modify: `resources/js/Pages/ERP/Manager/__tests__/EmploymentLifecycleApprovals.contract.test.ts`
- Modify: `resources/js/Pages/ERP/Manager/__tests__/SuspensionApprovals.contract.test.ts`

- [x] Add assertions for lifecycle status options, search/status query construction, and history-aware empty-state wording.
- [x] Add assertions that Suspension summary cards use neutral styling and the redundant request/latest-request card is absent.
- [x] Run the focused tests and confirm the new assertions fail against the current implementation.

### Task 2: Add lifecycle approval filters

**Files:**
- Modify: `resources/js/Pages/ERP/Manager/EmploymentLifecycleApprovals.tsx`

- [x] Track draft and applied `search`/`status` values, defaulting to `pending_manager`.
- [x] Build the existing endpoint query with `URLSearchParams`, omitting empty values and resetting pagination when filters are applied or cleared.
- [x] Render the same accessible filter controls and status options on both the termination and rehire routes.
- [x] Make the empty state describe the selected filters rather than always saying requests are pending.

### Task 3: Simplify Suspension summary cards

**Files:**
- Modify: `resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx`

- [x] Remove the header request-count/latest-request card.
- [x] Keep the four summary metrics but use neutral light/dark card styling; retain colored request status badges.

### Task 4: Verify and review

- [x] Re-run focused approval tests and the broader frontend suite, recording unrelated baseline failures if any.
- [x] Run `pnpm run build` and `git diff --check`.
- [x] Review changed files for reused endpoint contracts, accessible labels, and no unrelated workflow changes.

The full frontend suite currently has three unrelated failures: one existing 5-second
sidebar test timeout, an existing Manager sidebar expected-link-count contract that
does not include the lifecycle links already on this branch, and an obsolete
user-side Repairs heading contract.
