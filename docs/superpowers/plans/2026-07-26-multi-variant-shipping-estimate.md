# Multi-Variant Shipping Estimate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow checkout shipping estimates for multiple color or size variants that share one product ID and one shop.

**Architecture:** Preserve every cart variant as a separate order line. Change only the shipping-estimate request validation because its shop lookup already handles repeated product IDs through `whereIn`.

**Tech Stack:** Laravel, PHPUnit

---

### Task 1: Accept repeated product IDs in shipping estimates

**Files:**
- Modify: `tests/Feature/UserSide/ShippingEstimateControllerTest.php`
- Modify: `app/Http/Controllers/UserSide/ShippingEstimateController.php:47`

- [ ] **Step 1: Write the failing regression test**

Add a controller test that submits the same valid product ID twice for one shop.

- [ ] **Step 2: Run the new test and verify RED**

Run:

```bash
php artisan test tests/Feature/UserSide/ShippingEstimateControllerTest.php --filter=test_estimate_accepts_repeated_product_ids_for_variants_from_one_shop
```

Expected: FAIL with HTTP 422 and a duplicate-value validation error for `item_pids`.

- [ ] **Step 3: Implement the minimal fix**

Remove only the `distinct` rule from `item_pids.*`.

- [ ] **Step 4: Run focused and full controller verification**

Run:

```bash
php artisan test tests/Feature/UserSide/ShippingEstimateControllerTest.php --filter=test_estimate_accepts_repeated_product_ids_for_variants_from_one_shop
php artisan test tests/Feature/UserSide/ShippingEstimateControllerTest.php
```

Expected: PASS. Existing cross-shop, deleted-product, malformed-ID, and size-limit tests remain green.

- [ ] **Step 5: Commit only the fix files**

```bash
git add app/Http/Controllers/UserSide/ShippingEstimateController.php tests/Feature/UserSide/ShippingEstimateControllerTest.php docs/superpowers/plans/2026-07-26-multi-variant-shipping-estimate.md
git commit -m "fix: allow multi-variant shipping estimates"
```
