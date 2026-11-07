# Subscription Management Paid Amounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show verified subscription payments in the admin revenue cards and shop amount column, and set the browser tab title.

**Architecture:** Keep payment truth server-side in `SubscriptionManagementController`; use the existing payment ledger when present and a narrowly verified legacy subscription amount only when no ledger row exists. Keep UI changes limited to the displayed description, amount, and page title.

**Tech Stack:** Laravel 12, Eloquent query builder, Inertia 2, React 18, TypeScript, PHPUnit, Vitest, Vite.

---

### Task 1: Sync the requested feature worktree

**Files:** Git branch `fix/platform-fee-balance` only.

- [x] Fetch `origin` and fast-forward the feature branch to `origin/solespace-b` if the current feature tip is already an ancestor.
- [x] Preserve the existing untracked Laravel cache data; do not stage it.

### Task 2: Reproduce and fix paid amount serialization

**Files:**
- Modify: `app/Http/Controllers/superAdmin/SubscriptionManagementController.php`
- Test: `tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php`
- Test: `tests/Feature/PremiumBillingLedgerTest.php`

- [x] Add the regression assertion for verified legacy payments and confirm it fails before implementation.
- [x] Use paid ledger amounts when available; include legacy `paid_amount` only for a unique verified payment with no ledger entry.
- [x] Confirm webhook ledger rows persist `amount_paid` from the provider payment.

### Task 3: Fix admin page labels and tab title

**Files:**
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- Test: `resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx`

- [x] Add failing tests for the shop amount display and browser tab title.
- [x] Apply the minimal UI changes and verify the focused frontend tests.

### Task 4: Verify, build, and publish

**Files:**
- Regenerate: `public/build`

- [x] Run focused backend and frontend tests, then `git diff --check`.
- [x] Run a fresh production build and verify the build output.
- [x] Commit only the plan, related source/tests, and generated build; push `fix/platform-fee-balance` to origin.
