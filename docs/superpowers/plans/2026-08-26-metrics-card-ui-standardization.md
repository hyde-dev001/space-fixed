# Metrics Card UI Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Standardize every metrics card rendered by the Manager, Staff, Finance, HR, CRM, Cashier, Repairer, Inventory, Procurement, Logistics, and Shop Owner account pages to match the existing `EcommerceMetrics` dashboard card visual language.

**Architecture:** Keep `EcommerceMetrics` as the canonical existing component and update its presentation first. Update the local `MetricCard` implementations and inline metric-card markup in the affected role pages to reuse the same visual treatment without changing their data contracts, APIs, permissions, or business logic.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS 4, Vitest.

## Global Constraints

- Preserve unrelated working-tree changes.
- Do not create a second reusable metrics component.
- Preserve each page's existing metric labels, values, descriptions, status/change semantics, and icon meaning.
- Keep responsive layouts and dark-mode support.
- Use SVG icons already present in each page; do not introduce emoji icons or new dependencies.
- Verify with `pnpm run test:frontend`, `pnpm run build`, and `git diff --check` when practical.

---

### Task 1: Establish the canonical dashboard card treatment

**Files:**
- Modify: `resources/js/components/ecommerce/EcommerceMetrics.tsx`
- Test: existing dashboard/widget frontend tests if they cover the component

- [x] Update only the card presentation: subtle border, white surface, muted icon tile, compact label/value hierarchy, optional trend badge, and responsive sizing matching the approved reference.
- [x] Keep all existing formatting, null handling, repair-vs-retail labels, and growth semantics intact.
- [x] Run the narrow frontend test covering dashboard widgets.

### Task 2: Align local metric-card implementations across ERP roles

**Files:**
- Modify only affected metric-card definitions/usages under `resources/js/Pages/ERP/` for CRM, Finance, HR, Manager, Staff, cashier, repairer, inventory, procurement, and Logistics.

- [x] Replace gradient icon tiles, hover rotation/translation, decorative gradient overlays, and oversized shadows with the canonical neutral icon tile and card treatment.
- [x] Preserve optional change badges and descriptions as secondary content.
- [x] Preserve page-specific grid breakpoints and data behavior.
- [x] Run the frontend test suite after the ERP group is updated.

### Task 3: Align local metric-card implementations across Shop Owner roles and variants

**Files:**
- Modify affected metric-card definitions/usages under `resources/js/Pages/ShopOwner/`, including dashboard, orders, products, customers, repairs, approvals, settings, logistics, and team-management pages.

- [x] Apply the same canonical card treatment to all local metric cards and inline metric cards.
- [x] Preserve company/individual and retail/repair conditional rendering.
- [x] Preserve existing status and trend semantics, including low-stock, repeat-customer, pending, approved, rejected, and repair-specific metrics.
- [x] Run the frontend test suite after the Shop Owner group is updated.

### Task 4: Review and verify the complete visual migration

**Files:**
- Review: all changed metric-card files

- [x] Search changed role pages for remaining gradient metric icon classes, decorative card overlays, and inconsistent metric-card markup.
- [x] Confirm no unused imports or stale helper functions were introduced.
- [x] Run `pnpm run test:frontend`, `pnpm run build`, and `git diff --check`.
- [x] Report exact changed areas and verification results.
