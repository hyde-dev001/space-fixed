# Account Drawer and Glass Surfaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the shared user-side sidebar and cart visibly glassmorphic, and replace the desktop Account hover dropdown with a Kith-inspired click-open side panel containing the requested account actions.

**Architecture:** Keep the existing `Navigation` state and drawer layering. Add one `accountDrawerOpen` state and render Account as a fixed child panel immediately after the left sidebar, using the parent sidebar width as its left offset and the existing z-index scale. Reuse the current inline SVG icons and Inertia links; use Tailwind opacity, blur, transform, and opacity transitions without adding a dependency.

**Tech Stack:** Laravel 12, Inertia, React 18, TypeScript, Tailwind CSS 4, Vitest, Vite.

## Global Constraints

- Preserve unrelated working-tree changes.
- Use the existing shared navigation and CSS conventions; do not add a UI dependency.
- Keep Account click/tap as the primary interaction and support reduced motion.
- Keep the moving ticker below all drawer overlays and panels.
- Stage only requested source, test, plan, and generated build files.

---

### Task 1: Lock the drawer contract with a failing test

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`

- [x] **Step 1: Assert the Account panel and visible glass classes**

Update the existing drawer/account contract to require an `Account` panel with `z-[110]`, the three requested authenticated actions, click state, and translucent `bg-white/60` surfaces. Remove the old `Dropdown Menu` slice assertion.

- [x] **Step 2: Run the focused test and verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts --reporter=dot
```

Expected: FAIL because the current implementation still contains the old hover dropdown and `bg-white/90` drawers.

### Task 2: Implement the shared Account drawer and glass surfaces

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx`

- [x] **Step 1: Replace hover-only Account state with click-driven drawer state**

Add `accountDrawerOpen`, remove the old dropdown-only refs/effect/timeout, and close Account when opening Cart. Make the sidebar Account utility open the panel while preserving guest login access inside it.

- [x] **Step 2: Render the nested Account child panel**

Render a fixed `left-[min(88vw,31rem)] top-0` child panel with `role="dialog"`, `aria-modal`, a close button, `translate-x` entry/exit motion, and the authenticated actions `Edit Profile`, `Join Our Team`, and `Log out`. Keep the parent sidebar open while the child panel is visible.

- [x] **Step 3: Make both drawer surfaces visibly glass**

Use translucent white backgrounds, translucent borders, `backdrop-blur-2xl`, a lighter scrim blur, and the existing 300ms transform/opacity transitions for the Cart and Site menu.

- [x] **Step 4: Run the focused contract test and verify it passes**

Run the same Vitest command from Task 1. Expected: PASS with no failures.

### Task 3: Verify and deliver the requested branch update

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- Modify: generated tracked files under `public/build/`
- Create: `docs/superpowers/plans/2026-08-30-account-drawer-glass-surfaces.md`

- [x] **Step 1: Run frontend tests and diff hygiene checks**

Run the focused navigation tests, the full frontend suite with the local Vitest binary, and `git diff --check`.

- [x] **Step 2: Build a fresh production bundle**

Run `pnpm run build`; if the repository's Corepack signature issue blocks pnpm, run the local Vite binary and report both outcomes accurately.

- [x] **Step 3: Review the branch diff and commit only requested files**

Preserve unrelated ERP/package/untracked changes, commit the UI/test/plan/build update, rebase onto `origin/solespace-b`, and push only the feature branch. Do not create a PR.
