# Semantic Icon Actions and SweetAlert Colors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give interactive icon-only actions a consistent, accessible semantic color treatment while preserving SoleSpace's monochrome surfaces and text-button hierarchy.

**Architecture:** Add one shared semantic icon-action contract (`data-erp-icon-action` plus `data-semantic-color`) backed by ERP-scoped CSS tokens and a small reusable `IconButton` wrapper. Add a SweetAlert semantic-options helper and extend the existing user-facing SweetAlert wrapper so icon colors and confirmation actions are determined by meaning rather than page-local color values. Migrate high-signal table/card/modal actions, beginning with Employee Directory and shared action surfaces, without changing handlers, routes, permissions, or workflows.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, SweetAlert2, Vitest, Vite.

## Global Constraints

- Preserve the existing SoleSpace visual system: white, black, and neutral gray for layouts, cards, tables, fields, navigation, and ordinary text buttons.
- Keep existing icon-button dimensions, spacing, alignment, and click handlers.
- Use semantic tokens and shared helpers; do not introduce page-specific arbitrary semantic color strings.
- Disabled icon actions must be gray, approximately 38% opacity, non-hovering, and visibly non-interactive.
- Every icon-only action keeps an accessible name, title/tooltip, and visible keyboard focus.
- Do not modify backend behavior, permissions, routes, or business-state transitions.
- Preserve unrelated working-tree changes in the main checkout; work only in this isolated worktree.

---

## Task 1: Add failing semantic-contract tests

- [ ] Extend `resources/js/__tests__/monochromeTheme.test.ts` to require five semantic icon-action variants, ERP-scoped semantic tokens, disabled hover-inert selectors, and semantic SweetAlert icon/action selectors while retaining monochrome ordinary-control assertions.
- [ ] Add `resources/js/components/ui/icon-button/__tests__/IconButton.test.tsx` to define the shared wrapper's semantic data attributes, `aria-label`, title, default button type, and disabled behavior.
- [ ] Add `resources/js/utils/__tests__/semanticSweetAlert.test.ts` to define semantic SweetAlert custom-class merging and preservation of caller options.
- [ ] Add source-contract assertions for Employee Directory action mapping and sign-out semantics in the existing HR/header tests.
- [ ] Run the focused tests and record the expected failures before adding production implementation.

## Task 2: Implement shared semantic contracts

- [ ] Add `resources/js/components/ui/icon-button/IconButton.tsx` with a minimal typed API for neutral, primary, success, warning, and danger icon actions; preserve caller class names and existing button geometry.
- [ ] Add `resources/js/utils/semanticSweetAlert.ts` with typed semantic options for neutral/info, success, warning, danger, and sign-out dialogs.
- [ ] Append one final ERP-scoped semantic token/state block to `resources/css/app.css`, including light/dark resting, hover, active, focus-visible, and disabled states for icon actions.
- [ ] Add semantic SweetAlert icon and confirmation-button rules at the final cascade boundary; keep sign-out amber-icon/black-confirm behavior and ordinary acknowledgement buttons monochrome.

## Task 3: Migrate audited actions without workflow changes

- [ ] Replace Employee Directory's local colored icon-button classes with the shared semantic contract: view/details and permissions neutral, invitation blue, reset/suspend amber, termination red, and activation/rehire positive or communication text actions as appropriate.
- [ ] Apply the same contract to high-signal shared/table actions where their handlers are explicit: Shop Owner User Access Control, vouchers/discount actions, supplier/customer management actions, address actions, owner approval detail triggers, and existing POS/Job Orders markers.
- [ ] Add or preserve `aria-label` and title values for every migrated icon-only action, including disabled/self-account cases.
- [ ] Migrate sign-out calls in shared account dropdowns and the customer navigation wrapper to the shared semantic alert treatment; migrate key Employee Directory and voucher/delete/approval confirmations to explicit semantic alert options.
- [ ] Keep ordinary text buttons and the surrounding monochrome page surfaces unchanged.

## Task 4: Verify and review

- [ ] Run focused semantic/theme, component, HR, header, table-action, and SweetAlert tests; fix failures before broad verification.
- [ ] Run `node_modules\\.bin\\vitest.cmd run` and `node_modules\\.bin\\vite.cmd build` from the isolated worktree, using the repository's existing junctioned dependencies without reinstalling.
- [ ] Inspect generated `public/build` as the fresh build output after the rebase; do not hand-edit hashed assets.
- [ ] Perform standards, specification, simplification, reuse, dead-code, accessibility, and regression reviews; browser-check relevant icon and dialog states if an authenticated local runtime is available.
- [ ] Run `git diff --check`, inspect `git status --short`, `git diff --stat`, and the final diff; confirm only requested source, test, plan, and fresh-build files are included.
