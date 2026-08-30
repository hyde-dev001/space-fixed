# User-Side Header Safety and Transitions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove shared user-side header overlap and add reduced-motion-safe navigation transitions.

**Architecture:** Keep the fix in the shared navigation and the Inertia bootstrap entry point. Use route state already available to Navigation so Landing remains an overlay while all other shared-navigation routes are opaque. Use CSS classes toggled by Inertia lifecycle events rather than a new animation package.

**Tech Stack:** Laravel, Inertia React, TypeScript/JSX, Tailwind CSS 4, Vitest, Vite.

## Global Constraints

- Preserve all existing route handlers, forms, cart, payment, and authentication behavior.
- Do not add dependencies.
- Preserve the existing promo ticker, drawers, and mobile-header route behavior.
- Respect `prefers-reduced-motion`.

---

### Task 1: Lock the shared-header and transition contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`
- Modify: `resources/js/__tests__/appProviderBoundary.test.ts`

- [ ] Write source-contract assertions requiring a non-zero shared navigation height, opaque non-landing surface, and transition CSS/lifecycle hook.
- [ ] Run the focused tests and confirm they fail because the current header is zero height and the global transition hook is absent.

### Task 2: Correct shared desktop header stacking

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx`

- [ ] Give the fixed navigation an explicit height in both modes.
- [ ] Apply the transparent background only on the Landing route; use the existing white/blur surface elsewhere.
- [ ] Re-run the navigation contract test.

### Task 3: Add global page navigation motion

**Files:**
- Modify: `resources/js/app.jsx`
- Modify: `resources/css/app.css`

- [ ] Subscribe once to Inertia start/finish events in the bootstrap entry point and toggle a root class.
- [ ] Add a short fade-and-rise CSS animation with a reduced-motion override.
- [ ] Re-run the app boundary contract test.

### Task 4: Verify and deliver

**Files:**
- Modify: `public/build/**`

- [ ] Run the focused navigation and app tests, then the full frontend suite.
- [ ] Build fresh `public/build` from a clean tree, run `git diff --check`, rebase against `origin/solespace-b`, and push only intended files.
- [ ] Restore unrelated local work after the push.
