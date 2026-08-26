# Repair Request Card Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the bottom of the `Repair Request` workload card with the neighboring `Daily Active Repairs` card without reintroducing the expanded white meter gap.

**Architecture:** Let the workload grid use its normal equal-height row behavior. The `UtilizationOverviewCard` will be a full-height flex column, but only its lower gray metrics region will grow; the white meter/content panel stays natural-height. This keeps the shared card bottoms aligned while preserving the visual separation between the meter and metrics.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Modify only the DSS Insights layout contract, its focused regression test, the related plan, and generated `public/build` output.
- Preserve existing data, API behavior, responsive breakpoints, dark-mode classes, and unrelated working-tree changes.
- Use the existing workload card structure; do not add a new component or dependency.
- Use `pnpm` commands where available; use the repository's local Node entry points only if the Windows pnpm wrapper is blocked by execution policy.
- Rebase onto `origin/solespace-b` before pushing and push only `fix/darkmode-selected-size`.

---

### Task 1: Update the regression contract

**Files:**
- Modify: `resources/js/__tests__/repairRequestCardLayout.test.ts`
- Read: `resources/js/Pages/ShopOwner/DssInsights.tsx`

- [x] Assert that the workload grid no longer opts into `items-start` and therefore permits equal-height row stretching.
- [x] Assert that the utilization card keeps `h-full flex flex-col` on its outer shell.
- [x] Assert that the white meter panel does not use `flex-1`.
- [x] Assert that the lower metrics region uses a flex-growing wrapper to absorb the row height.
- [x] Run the focused Vitest test and confirm it fails against the current natural-height implementation.

### Task 2: Implement the aligned card layout

**Files:**
- Modify: `resources/js/Pages/ShopOwner/DssInsights.tsx`

- [x] Restore equal-height stretching on the workload grid by removing `items-start`.
- [x] Restore `h-full flex flex-col` only on the outer utilization shell.
- [x] Keep the white meter section natural-height without `flex-1`.
- [x] Wrap the two gray metric rows in a `flex flex-1 flex-col` region so any extra row height is visually owned by the gray metrics surface instead of the white meter panel.
- [x] Run the focused test and confirm it passes.

### Task 3: Verify the full change and generated assets

**Files:**
- Modify: `public/build/` (generated Vite output only)

- [x] Run the frontend suite and relevant Laravel Logistics tests.
- [x] Run a fresh frontend build and validate the manifest has no missing assets.
- [x] Run `git diff --check` and review the branch diff against `origin/solespace-b` for unexpected files or deletions.

### Task 4: Rebase, commit, and push

**Files:**
- Commit only the intended source, test, plan, and generated build files.

- [ ] Temporarily preserve unrelated local edits if needed.
- [ ] Fetch and rebase onto `origin/solespace-b`.
- [ ] Commit the layout fix and fresh build with focused messages.
- [ ] Restore unrelated local edits without staging them.
- [ ] Push `fix/darkmode-selected-size` (using `--force-with-lease` only if the rebase makes a normal push non-fast-forward).
- [ ] Verify the remote feature ref equals local HEAD.
