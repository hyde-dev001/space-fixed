# Shop-Owned Logistics Vouchers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Let an eligible shop owner create flexible shipping vouchers (percentage or fixed amount, with any minimum spend) for Shop-owned Logistics, apply them authoritatively during checkout, and show the discounted shipping fee and total on the payment page without changing existing product voucher behavior.

**Architecture:** Extend `PromoCampaign` with a backward-compatible `discount_target` (`items` or `shipping`). Shipping campaigns remain voucher-only and shop-wide. A small backend service will validate module access, minimum spend, delivery coverage, and capped discount math. Checkout preview and order creation will both use that service; persisted order shipping remains authoritative during payment retries. The shop-owner page will expose the target only when the logistics module is accessible, while payment will render the original fee, shipping voucher savings, discounted fee, and updated total.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, PHPUnit, Vite 7, pnpm.

## Global constraints

- Preserve unrelated work in the parent checkout and never edit `.env`, `vendor/`, or `node_modules/`.
- Follow `docs/git-workflow.md`: work only on `feature/logistics-vouchers`, stage specific files, rebase on `origin/solespace-b` before pushing, and push the feature branch only. The user will create the PR.
- Keep existing product voucher/sale API contracts and checkout totals working; default missing/legacy targets to `items`.
- Treat the client-provided shipping fee and totals as display/request inputs only. Recompute and cap shipping voucher savings on the server, scope addresses to the authenticated user, and persist the final shipping fee on the order.
- Use `DESIGN.md` and the existing page styles: neutral SoleSpace palette, 8px spacing rhythm, accessible 44px controls, clear status/error text, and no emoji.
- Do not introduce a new dependency or a speculative abstraction.

## Task 1: Add the target contract and owner-side logistics eligibility

**Files:**

- Create `database/migrations/2026_08_22_000001_add_discount_target_to_promo_campaigns_table.php`.
- Modify `app/Models/PromoCampaign.php`.
- Modify `app/Http/Controllers/ShopOwner/PromoCampaignController.php`.
- Modify `tests/Feature/ShopOwner/PromoCampaignApiTest.php`.

**TDD steps:**

1. Add failing feature coverage for the new `discount_target` field, successful shipping-voucher creation for a company shop with enabled logistics, rejection for a shop without accessible logistics, rejection of product-specific shipping vouchers, and preservation of the existing product voucher flow.
2. Run the focused API test with a process-only app key and confirm the new assertions fail for the expected missing field/validation behavior.
3. Add the migration with a safe `items` default and reversible down migration; add the model fillable/attribute default.
4. Validate `discount_target`, enforce shipping-voucher invariants, authorize through `ShopModuleAccessService`, and include a backward-compatible logistics capability object in the index response.
5. Run the focused API tests again and inspect the diff.

**Verification:**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/ShopOwner/PromoCampaignApiTest.php
git diff --check
```

## Task 2: Apply shipping vouchers in preview, order creation, and payment retry

**Files:**

- Create `app/Services/Logistics/ShippingVoucherService.php`.
- Modify `app/Http/Controllers/UserSide/CheckoutController.php`.
- Modify `tests/Feature/CheckoutPromoPricingTest.php`.

**TDD steps:**

1. Add failing tests for percentage and fixed shipping vouchers, arbitrary minimum-spend values, cap-at-shipping/free-shipping behavior, eligible coverage, no-logistics behavior, preview response fields, order persistence/redemption, and retry preservation of a persisted zero shipping fee.
2. Run the focused checkout tests and verify they fail because the target/service/response behavior does not exist yet.
3. Implement `ShippingVoucherService` with module authorization, coverage checks using `DeliveryScheduleService`, minimum-spend checks against the sale-adjusted item subtotal, safe error messages, two-decimal rounding, and a discount cap at the raw shipping fee.
4. Wire it into promo preview and order creation. Keep product pricing in `PromoPricingService`, allow one explicit voucher per order, and make a selected shipping voucher occupy the voucher slot without auto-applying shipping vouchers.
5. Add authenticated-user address validation and coordinates to the preview path. Persist the discounted shipping fee and redeem the selected campaign consistently with product vouchers.
6. Change payment-session retry fallback so a persisted `0.00` shipping fee is not replaced by the raw client fee; only a genuinely null legacy value may use the fallback.
7. Run the focused checkout tests and the existing promo/shipping tests.

**Verification:**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/CheckoutPromoPricingTest.php tests/Feature/UserSide/ShippingEstimateControllerTest.php tests/Feature/ShopOwner/PromoCampaignApiTest.php
git diff --check
```

## Task 3: Expose the target in the shop-owner UI and payment totals

**Files:**

- Modify `resources/js/Pages/ShopOwner/Orders/order management/discount.tsx`.
- Modify `resources/js/Pages/UserSide/Orders/payment.tsx`.
- Create `resources/js/Pages/ShopOwner/Orders/order management/__tests__/discountLogisticsIntegration.test.ts`.
- Create `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts`.

**TDD steps:**

1. Add focused source-integration tests for target payloads, logistics capability handling, payment preview inputs/response fields, shipping savings display, discounted total calculation, and retry payload behavior.
2. Run the new frontend tests and confirm they fail against the current source.
3. Update the shop-owner types/form/API mapping. Add a clear Items vs Shipping target control for vouchers, hide/clear product selection for shipping, explain that shipping vouchers require accessible Shop-owned Logistics, and render target-aware preview/tracker data. Keep sale/product behavior unchanged.
4. Update payment promo-preview requests with the raw shipping fee, selected address, and coordinates. Add target-aware voucher summaries and render original shipping, shipping-voucher savings, discounted shipping, error state, and the recalculated total in both responsive summary layouts.
5. Keep the existing pay request’s raw shipping fee for server computation and ensure retry requests cannot visually or server-side undo persisted free shipping.
6. Run the focused frontend tests and the full frontend suite.

**Verification:**

```powershell
pnpm.cmd test:frontend -- --run resources/js/Pages/ShopOwner/Orders/order management/__tests__/discountLogisticsIntegration.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
pnpm.cmd test:frontend
```

## Task 4: Review, build, and publish the feature branch

**Files/areas:** changed application files, tests, documentation, and generated `public/build` output only.

1. Run a sequential standards/spec/security/simplification review against the acceptance criteria. Check authorization, address ownership, server-side totals, legacy fallback, unused imports, stale branches, and direct-import/bundle impact.
2. Run the focused backend/frontend checks, `git diff --check`, and the repository’s required broader checks. If the full Composer suite remains blocked by the repository’s missing `.env`/`APP_KEY`, report that exact limitation and retain the successful process-key focused evidence.
3. Run `pnpm.cmd build` and inspect the generated output. Include a fresh `public/build` in the feature commit as requested, staging only generated build files from that directory.
4. Re-read `docs/git-workflow.md`, inspect `git diff --name-status` and `git diff --stat` against `origin/solespace-b`, fetch/rebase the feature branch, rerun the pre-push commands, and push `feature/logistics-vouchers`.
5. Leave PR creation to the user and report the target branch, commits, exact verification results, and any environment limitation.

**Final verification commands:**

```powershell
git diff --check
php artisan test tests/Feature/Logistics
pnpm.cmd test:frontend
pnpm.cmd build
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
```
