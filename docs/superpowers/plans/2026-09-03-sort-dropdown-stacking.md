# Sort Dropdown Stacking Context Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure the open `Sort by:` dropdown renders above animated product and repair-shop cards without changing sorting behavior.

**Architecture:** Preserve the existing absolutely positioned menus and click-away handlers. Establish a higher stacking context on each existing sort-header wrapper and give its menu a higher local z-index than the card stacking contexts.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS, Vitest, Vite.

## Global Constraints

- Change only the Products and Repair Services sort-menu presentation plus focused regression tests and this documentation.
- Preserve unrelated working-tree changes.
- Do not change sorting state, option values, data fetching, or backend code.
- Use existing Tailwind classes and layout-test conventions; add no dependency.

## File Map

- `resources/js/Pages/UserSide/Products/Products.tsx` — raise the Products sort header and menu stacking levels.
- `resources/js/Pages/UserSide/Products/Products.layout.test.ts` — assert the Products menu is layered above cards.
- `resources/js/Pages/UserSide/Repairs/Repair.tsx` — raise the Repair Services sort header and menu stacking levels.
- `resources/js/Pages/UserSide/Repairs/Repair.layout.test.ts` — assert the Repair Services menu is layered above cards.
- `docs/superpowers/specs/2026-09-03-sort-dropdown-stacking-design.md` — approved design and root cause.

## Implementation Tasks

### Task 1: Add the regression contract

- [x] Extend both existing layout tests with assertions for the sort-header `relative z-30` wrapper and dropdown `z-40` panel.
- [x] Run the focused tests and confirm the new assertions fail before the production change.

### Task 2: Apply the minimal UI fix

- [x] Add `relative z-30` to the existing scroll-reveal sort-header wrapper in Products and Repair Services.
- [x] Change only the dropdown panel z-index from `z-20` to `z-40` in both pages.
- [x] Re-run the focused tests and confirm they pass.

### Task 3: Verify and review

- [x] Run the full Vitest suite.
- [x] Run a Vite production build into a temporary output directory and remove only that generated directory afterward.
- [x] Run `git diff --check` and inspect the final diff for scope, reuse, dead code, and unchanged sort behavior.
- [x] Attempt browser verification; local routes returned a blank Laravel shell, so rendered dropdown verification was unavailable.

## Acceptance Criteria

- Opening `Sort by:` on `/products` keeps the menu visually above the product cards.
- Opening `Sort by:` on `/repair-services` keeps the menu visually above the shop cards.
- Existing sorting options and interaction behavior remain unchanged.
- Focused tests, full tests, build, and diff hygiene provide fresh verification evidence.

## Verification Record

- Focused layout tests: 2 files, 6 tests passed.
- Full Vitest suite: 173 files, 951 tests passed.
- Vite production build: 3735 modules transformed; passed.
- Diff hygiene: `git diff --check HEAD~2..HEAD` passed.
- Browser probe: both routes returned HTTP 200 without page/console/request errors, but rendered an empty local Laravel shell and exposed no sort button.
