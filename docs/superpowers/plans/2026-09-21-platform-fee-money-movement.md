# Platform Fee Money Movement Implementation Plan

> **For agentic workers:** Execute this plan task-by-task with test-first checkpoints.

**Goal:** Make credit issuance and credit application against Platform Fee balances visible to shop owners, Finance, and Super Admins.

**Architecture:** Reuse `PlatformCreditApplication` and `PlatformFeePaymentAllocation` as the authoritative ledger. Extend the existing balance/admin payloads with a compact summary and movement rows; render those rows in the existing Platform Balance and Platform Fees pages. No new tables or separate audit module.

**Tech Stack:** Laravel 12, Eloquent, Inertia, React 18, TypeScript, Tailwind, PHPUnit, Vitest.

---

### Task 1: Add a failing ledger-presentation contract test

**Files:**
- Create: `tests/Feature/PlatformFee/PlatformFeeMoneyMovementTest.php`
- Inspect/reuse: `tests/Feature/PlatformFee/PlatformFeeRefundTest.php`

- [x] Create a marketplace charge, a paid platform-fee allocation, a refund credit, and a later charge that consumes part/all of the credit.
- [x] Assert the finance/owner payload exposes issued credit, applied credit, remaining credit, and before/after balance movement.
- [x] Assert the admin Inertia payload keeps earned fees separate from the affected shop's applied/remaining credit values.
- [x] Run the focused test and verify it fails because the new payload keys are absent.

### Task 2: Expose authoritative movement data from existing server flows

**Files:**
- Modify: `app/Services/PlatformBalanceService.php`
- Modify: `app/Http/Controllers/Api/Finance/PlatformFeeController.php`
- Modify: `app/Http/Controllers/superAdmin/PlatformFeeAdminController.php`

- [x] Add a small reusable summary/movement projection based on credit applications and credit allocations.
- [x] Keep calculations decimal-safe and scoped to marketplace records for the current shop/admin shop set.
- [x] Include source type/id, credit issued, credit applied, remaining credit, status, and timestamps.
- [x] Include balance-before-credit and balance-after-credit values so users can see the actual deduction.
- [x] Keep earned fees separate from credit offsets and expose per-shop movement values.
- [x] Run the new focused test and the existing platform fee refund/payment tests.

### Task 3: Make the movement visible in existing pages

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/PlatformBalance.tsx`
- Modify: `resources/js/Pages/superAdmin/PlatformFees.tsx`
- Modify: `resources/js/Pages/superAdmin/PlatformFees.test.tsx`

- [x] Add a compact accessible Money movement panel to the shop owner/Finance page before the fee-charge table.
- [x] Keep the essential admin metric cards and show credit detail in the per-shop movement table.
- [x] Use explicit labels and text, not color alone, for issued/applied/remaining credit statuses.
- [x] Preserve existing payment/settings flows and avoid a second ledger implementation in the frontend.
- [x] Keep long owner/Finance credit movement history in a paginated modal so the page remains compact.
- [x] Keep admin credit movement history in a paginated modal so 100+ movements do not expand the page.
- [x] Add a per-shop limit editor with the current reliability score and score-based recommendation.
- [x] Keep Platform Fee SweetAlerts above the settings and limit modals, including reauthentication redirects.
- [x] Notify the affected shop owner/Finance recipients when a balance limit changes or a fee/VAT rate increases.
- [x] Include before/after setting and limit values in the platform-fee audit log properties.
- [x] Run the focused frontend test.

### Task 4: Verify and review

- [x] Run PHP lint on changed PHP files.
- [x] Run `git diff --check`.
- [x] Run focused PHPUnit and Vitest tests.
- [x] Run the frontend build if the environment permits it.
- [x] Review changed files for authorization scope, decimal handling, stale references, and unused imports.
