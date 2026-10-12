# Repair Request Meter Spacing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the stretched blank area below the `Repair Request` meter while preserving its metrics and responsive workload layout.

**Architecture:** Reuse the existing `MonthlyTarget` natural-height card pattern. The workload grid will opt out of equal-height stretching, and `UtilizationOverviewCard` will no longer force its white meter section to absorb the neighboring chart's height. No data, API, or component extraction changes are needed.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Use the existing `resources/js/Pages/ShopOwner/DssInsights.tsx` component and Tailwind classes.
- Keep the `Repair Request` meter, lower statistic rows, dark-mode classes, and responsive breakpoints unchanged except for height behavior.
- Preserve unrelated working-tree changes.
- Use `pnpm` for frontend commands.
- Generate a fresh `public/build` after the source change.

---

### Task 1: Add a regression test for natural-height workload cards

**Files:**
- Create: `resources/js/__tests__/repairRequestCardLayout.test.ts`
- Read: `resources/js/Pages/ShopOwner/DssInsights.tsx`

**Interfaces:**
- Consumes: the source markup for `UtilizationOverviewCard` and the workload tab grid.
- Produces: a focused Vitest contract that fails while the card still uses forced height/flex growth.

- [x] **Step 1: Write the failing test**

Add a source-level regression test that scopes its assertions to `DssInsights.tsx`, verifies the workload grid contains `items-start`, verifies the utilization card no longer contains `h-full flex flex-col`, verifies its white meter panel no longer contains `flex-1`, and verifies the call site no longer passes `className="h-full"`.

- [x] **Step 2: Run the test to verify it fails**

Run:

```bash
node node_modules/vitest/vitest.mjs run resources/js/__tests__/repairRequestCardLayout.test.ts --reporter=dot
```

Expected: FAIL because the current source still contains the forced height and flex-growth classes and does not contain `items-start`.

### Task 2: Remove the height growth that creates the blank space

**Files:**
- Modify: `resources/js/Pages/ShopOwner/DssInsights.tsx:478-479,1091-1099`

**Interfaces:**
- Consumes: the existing `UtilizationOverviewCard` and workload grid.
- Produces: a natural-height `Repair Request` card whose lower stats follow the meter content directly.

- [x] **Step 1: Implement the minimal layout change**

Apply these exact class changes:

```tsx
<div className={`rounded-2xl border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-white/[0.03] overflow-hidden ${className ?? ""}`}>
  <div className="px-5 pt-5 bg-white shadow-default rounded-2xl pb-8 dark:bg-gray-900 sm:px-6 sm:pt-6">
```

and:

```tsx
<div className="grid grid-cols-1 lg:grid-cols-3 items-start gap-5">
  <UtilizationOverviewCard
    workload={data?.workload}
    workloadLimit={data?.workload_limit ?? 0}
    period={period}
    loading={loading}
  />
```

- [x] **Step 2: Run the regression test to verify it passes**

Run the same Vitest command from Task 1. Expected: 1 test file and all assertions pass.

### Task 3: Verify the page and generated assets

**Files:**
- Modify: `public/build/` (generated Vite output only)

**Interfaces:**
- Consumes: the updated `DssInsights.tsx` source.
- Produces: fresh browser assets and evidence that the layout regression is resolved.

- [x] **Step 1: Run the frontend test suite**

Run:

```bash
pnpm run test:frontend
```

Expected: the frontend test suite passes. The repository's pnpm version switcher was unavailable in this environment, so the equivalent local Vitest command was used.

- [x] **Step 2: Build the frontend**

Run:

```bash
pnpm run build
```

Expected: Vite completes successfully and refreshes `public/build`.

- [ ] **Step 3: Run browser verification**

Open the DSS Insights page in the local app with Playwright, wait for `networkidle`, and verify that the `Repair Request` card renders with its metric rows immediately below the meter description without the previous expanded white gap. Capture a screenshot only as test evidence.

Verification note: the local app server started successfully, but the environment has neither the Python Playwright package nor a Node Playwright dependency, so authenticated browser rendering could not be executed.

- [x] **Step 4: Check the final diff**

Run:

```bash
git diff --check
```

Expected: no whitespace errors and no files changed outside the intended source/test/build paths, aside from pre-existing unrelated working-tree changes.
