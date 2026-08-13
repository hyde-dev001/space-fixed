# SoleSpace Attendance Tablet Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the phone attendance experience through tablet widths and guarantee SoleSpace branding in the compact ERP header.

**Architecture:** Move the shared ERP shell and attendance desktop transitions from `lg`/`md` to `xl`, using existing Tailwind classes. Render the compact header brand directly in React so desktop sidebar branding and attendance behavior remain untouched.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- No backend, route, attendance-rule, or database changes.
- No new dependency or component abstraction.
- Phone and tablet use compact layout below `xl`; desktop starts at `xl`.
- Include a fresh `public/build` and follow `docs/git-workflow.md` before push.

---

### Task 1: Lock the compact ERP shell contract

**Files:**
- Modify: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`
- Modify: `resources/js/layout/AppHeader_ERP.tsx`
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`

**Interfaces:**
- Consumes: existing `SidebarContext` state and ERP shell components.
- Produces: compact shell below `xl`, desktop shell at `xl`, and visible `SoleSpace` compact branding.

- [x] **Step 1: Write failing assertions** for `SoleSpace`, `xl:hidden`, `xl:block`, and `xl:translate-x-0` responsive contracts.
- [x] **Step 2: Run focused shell tests** with the local Vitest binary and verify failure is caused by the old `lg` boundary or image-only brand.
- [x] **Step 3: Implement the minimal shell change** by replacing only responsive boundary classes and compact header markup.
- [x] **Step 4: Re-run focused shell tests** and confirm they pass.

### Task 2: Keep attendance compact through tablet widths

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`
- Modify: `resources/js/Pages/ERP/STAFF/TimeIn.tsx`

**Interfaces:**
- Consumes: existing attendance markup, state, handlers, and records.
- Produces: stacked dashboard and card history below `xl`; desktop grid and table at `xl`.

- [x] **Step 1: Write failing assertions** requiring `xl:grid-cols-5`, `xl:items-stretch`, `xl:h-full`, `xl:hidden`, and `xl:block`, while rejecting the old tablet desktop transitions.
- [x] **Step 2: Run the focused attendance test** and verify it fails for the expected breakpoint mismatch.
- [x] **Step 3: Implement the minimal responsive class changes** without changing behavior or data flow.
- [x] **Step 4: Re-run the focused attendance test** and confirm it passes.

### Task 3: Verify, build, and deliver

**Files:**
- Regenerate: `public/build/**`
- Update if durable guidance changes: `docs/ai-learning-log.md`

**Interfaces:**
- Consumes: completed source and test changes.
- Produces: verified source commit and fresh production assets on `fix/attendance-responsive-branding`.

- [x] **Step 1: Run focused tests, then the full frontend suite.** Expected: all test files and tests pass.
- [x] **Step 2: Run the Vite production build.** Expected: successful build and fresh `public/build` manifest/assets.
- [x] **Step 3: Run `git diff --check` and scope review.** Expected: no whitespace errors, unrelated files, or unexpected deletions outside generated build rotation.
- [x] **Step 4: Commit intended files, fetch/rebase `origin/solespace-b`, rebuild if necessary, and push the feature branch.** Expected: local and remote feature tips match; no PR is created.

## Plan self-review

- Every approved requirement maps to a task.
- No placeholder steps, new APIs, or speculative abstractions are present.
- Breakpoint naming is consistent: compact below `xl`, desktop at `xl`.
