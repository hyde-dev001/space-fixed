# Shop Owner Voucher Creation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Restore the existing Shop Owner voucher and product-discount page for eligible Retail and Both shops without weakening tenant or actor authorization.

**Architecture:** Reuse `VouchersDiscountPage` and `PromoCampaignController`. Remove only the Phase 5 explicit deny entries for the already-registered promo API routes, keep the existing ERP audience/actor middleware and controller shop scoping, and expose the existing page in the canonical Retail navigation. No new promo data model or duplicate API is introduced.

**Tech Stack:** Laravel 12, PHPUnit feature tests, Inertia/React 18, TypeScript, Vitest.

---

### Task 1: Add failing authorization and navigation regression coverage

**Files:**
- Modify: `tests/Feature/ShopOwner/PromoCampaignApiTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php` or the existing owner page contract test
- Test: relevant Shop Owner frontend navigation test

- [x] Assert an eligible Retail/Both Shop Owner can read products, list promos, and create a product-scoped voucher.
- [x] Assert another tenant's products/promos remain invisible and non-Retail owners remain denied.
- [x] Assert the canonical Retail navigation exposes the voucher destination.
- [x] Run the focused tests first; the initial red run confirmed the Phase 5 deny map blocked promo APIs and the canonical catalog lacked the page.

### Task 2: Restore the owner-scoped promo surface

**Files:**
- Modify: `config/shop_modules.php`
- Modify: canonical owner module catalog/navigation source identified by the failing navigation test

- [x] Remove only the six explicit owner-deny entries for the existing promo API routes.
- [x] Preserve `auth:shop_owner`, `check.business.type:retail,both`, `erp.audience`, and `erp.actor` middleware.
- [x] Add the existing voucher page to the canonical Retail local-page catalog at `/shop-owner/erp/retail/discounts`; the compatibility page remains `/shop-owner/vouchers-discount`.
- [x] Do not expose POS or unrelated legacy operations.

### Task 3: Verify the complete voucher workflow

**Files:**
- No new production files unless a focused failing test identifies a missing owner-scoping guard.

- [x] Run focused backend and frontend tests.
- [x] Run the covered Shop Owner backend and frontend suites.
- [x] Run `git diff --check` and inspect the final diff for tenant, input-validation, CSRF, and authorization regressions.
