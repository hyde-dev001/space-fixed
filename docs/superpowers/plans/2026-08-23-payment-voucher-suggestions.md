# Payment Voucher Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make payment-page voucher suggestions explain their benefit and current-order eligibility, expose claim state, and support a safe one-click `Claim & use` flow.

**Architecture:** Keep the existing `/api/checkout/promo-preview` response and `/api/products/{productId}/vouchers/{campaignId}/claim` mutation. The checkout controller adds server-authoritative suggestion metadata using existing pricing and shipping services; the payment page renders that metadata in the existing dropdown and reuses the current preview refresh after a successful claim.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, PHPUnit, pnpm, Vite.

## Global Constraints

- Change only the payment-page voucher suggestion experience; do not redesign the shop-owner voucher page.
- Preserve existing checkout totals, redemption, and Shop-owned Logistics rules.
- Do not add a dependency, migration, or `.env` change.
- Use `pnpm` for frontend commands and keep `public/build` fresh.
- Preserve unrelated worktree changes and push only the feature branch.

---

### Task 1: Lock the response and claim behavior with failing tests

**Files:**
- Modify: `tests/Feature/CheckoutPromoPricingTest.php`
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts`

**Interfaces:**
- Consumes: Existing promo preview endpoint and existing authenticated claim route.
- Produces: Assertions for the new `claim_status`, `eligibility`, `eligibility_message`, `remaining_spend`, `claim_product_id`, and `can_claim` fields plus the frontend card contract.

- [ ] **Step 1: Add a backend test fixture with claimed, claimable, redeemed, minimum-spend, and shipping vouchers.**

Use one retail shop/product and one authenticated customer. Create active code vouchers, create a `claimed` record for one, a `redeemed` record for another, and leave one unclaimed. Assert the preview response contains:

```php
$response->assertJsonPath('data.voucher_code_suggestions.0.claim_status', 'claimable')
    ->assertJsonPath('data.voucher_code_suggestions.0.eligibility', 'eligible')
    ->assertJsonPath('data.voucher_code_suggestions.0.can_claim', true)
    ->assertJsonPath('data.voucher_code_suggestions.0.claim_product_id', $product->id);
```

Add a second voucher with `min_spend` above the sale-adjusted cart subtotal and assert `eligibility` is `minimum_spend`, `can_claim` remains true for a shop-wide voucher, and `remaining_spend` is positive.

- [ ] **Step 2: Run the focused backend test and verify it fails on missing response fields.**

Run:

```powershell
php artisan test tests/Feature/CheckoutPromoPricingTest.php --filter="voucher suggestion"
```

Expected: FAIL because the current response only contains the original six summary fields and filters redeemed suggestions out.

- [ ] **Step 3: Extend the frontend integration test with the expected typed contract and interaction markers.**

Add assertions that `payment.tsx` contains the new status/eligibility types, `Claim & use`, `Claim for later`, `role="listbox"`, `aria-expanded`, the existing claim route pattern, and the CSRF header.

- [ ] **Step 4: Run the focused frontend test and verify it fails before implementation.**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
```

Expected: FAIL because the new contract and UI strings do not exist yet.

### Task 2: Enrich checkout voucher suggestions from server-side rules

**Files:**
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php:397-557,590-643,773-856`

**Interfaces:**
- Consumes: `PromoPricingService`, `ShippingVoucherService`, `PromoCampaign`, `VoucherClaim`, current preview cart line items, and current shipping context.
- Produces: Stable suggestion objects with claim state, eligibility state, human-readable reason, and an authorized cart product ID for claim requests.

- [ ] **Step 1: Include active code campaigns with redeemed state available for display.**

Keep the active date, status, code, and usage-capacity filters in `voucherCodeSuggestionCampaignsForCheckout`, but stop excluding the customer’s redeemed campaign IDs. The summarizer will mark them `redeemed` and the frontend will disable the action.

- [ ] **Step 2: Add one claim-status query for the summarized campaign IDs.**

Query `voucher_claims` by shop owner, user, and campaign IDs once. Map each campaign to its current status so rendering the two arrays does not create an Eloquent query per suggestion.

- [ ] **Step 3: Add eligibility helpers using existing pricing and shipping services.**

For item vouchers, call `voucherEligibleSubtotalFromPricedLineItems`. Return `not_applicable` when the eligible subtotal is zero, `minimum_spend` with `remaining_spend` when below the threshold, and `eligible` otherwise. For shipping vouchers, call `ShippingVoucherService::apply` with the preview’s raw fee, sale-adjusted subtotal, address, and coordinates; map its error to `shipping_unavailable` or `shipping_fee_required` and use its success result as `eligible`.

For `claim_product_id`, return the first server-loaded cart product that matches the campaign scope. A shop-wide campaign uses the first cart product; a product-specific campaign uses its first matching product. Return `null` when no product is authorized for that campaign.

- [ ] **Step 4: Pass the preview context into both voucher summary arrays.**

Build one context after products, pricing, and shop owner are loaded. Cache shipping evaluation results by campaign ID while summarizing both arrays. Preserve all existing totals and `applied_voucher` behavior.

- [ ] **Step 5: Run the focused backend tests and the existing promo test class.**

Run:

```powershell
php artisan test tests/Feature/CheckoutPromoPricingTest.php --filter="voucher suggestion"
php artisan test tests/Feature/CheckoutPromoPricingTest.php
```

Expected: The new metadata assertions and all existing pricing/redemption tests pass.

### Task 3: Replace the suggestion rows with accessible voucher cards

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:92-118,185-193,2188-2215,3238-3347`
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts`

**Interfaces:**
- Consumes: The enriched `AvailableVoucherOption` response and the existing payment state/preview flow.
- Produces: A mobile-friendly listbox of voucher cards with explicit status, eligibility, and action states.

- [ ] **Step 1: Add the exact TypeScript unions and fields matching the backend contract.**

Add `VoucherClaimStatus`, `VoucherEligibility`, and the optional metadata fields to `AvailableVoucherOption`. Keep existing fields required and normalize absent optional values safely.

- [ ] **Step 2: Add claim state and an authenticated claim handler.**

Track the campaign ID currently being claimed. Post to:

```typescript
`/api/products/${voucher.claim_product_id}/vouchers/${voucher.id}/claim`
```

with `credentials: 'include'`, `Accept`, `Content-Type`, and the CSRF token. On a successful response, select the voucher, set its code, close the listbox, and allow the existing promo-preview effect to apply it. On failure, keep the panel open and show the returned JSON message.

- [ ] **Step 3: Add small pure display helpers in the existing page.**

Format the benefit from `discount_mode`, `value`, and `target`; format PHP amounts with the existing locale pattern; and map eligibility/status to neutral, emerald, amber, or red classes without introducing a new component library.

- [ ] **Step 4: Replace each single-line suggestion button with a card option.**

Keep the input and `Apply` button unchanged. Render a `role="listbox"` panel with each card as a focusable `role="option"`, `aria-selected`, `aria-disabled`, and a visible 44px action. Use a non-nested action structure: the card is an option button only when directly usable; claim actions are separate buttons within the card container. Show the benefit, target/scope, minimum-spend progress, eligibility message, claim status, and action copy.

- [ ] **Step 5: Keep keyboard and outside-click behavior intact.**

Retain Escape/outside click handling, add `aria-expanded` to the input, and ensure disabled cards cannot select a voucher. Keep the dropdown’s height bounded so it does not push the payment summary off-screen.

- [ ] **Step 6: Run focused frontend tests and build the frontend.**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
pnpm run build
```

Expected: Focused test passes and Vite writes a fresh `public/build`.

### Task 4: Review, verify, and publish the branch

**Files:**
- Review: `app/Http/Controllers/UserSide/CheckoutController.php`
- Review: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Review: `tests/Feature/CheckoutPromoPricingTest.php`
- Review: `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts`
- Generated: `public/build/*`
- Documentation: `docs/superpowers/specs/2026-08-23-payment-voucher-suggestions-design.md`, `docs/superpowers/plans/2026-08-23-payment-voucher-suggestions.md`

**Interfaces:**
- Consumes: All implementation and test changes from Tasks 1–3.
- Produces: A clean, verified feature branch pushed to `origin` for the user’s PR.

- [ ] **Step 1: Run the required sequential reviews.**

Check simplification/duplication, Laravel authorization and query count, TypeScript narrowing and React event behavior, checkout regression risk, and the approved UX scope. Do not add unrelated refactors.

- [ ] **Step 2: Run the quality gates.**

Run:

```powershell
php artisan test tests/Feature/CheckoutPromoPricingTest.php
pnpm run test:frontend
pnpm run build
git diff --check
```

Do not push if any command fails or if the diff contains unrelated files.

- [ ] **Step 3: Inspect the final diff against `origin/solespace-b`.**

Run:

```powershell
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
git status --short --branch
```

Confirm only the planned backend, payment UI/test, documentation, and generated build changes are present.

- [ ] **Step 4: Commit the implementation and push the task branch.**

Use specific paths when staging, then run:

```powershell
git commit -m "feat: improve payment voucher suggestions"
git push -u origin feature/payment-voucher-suggestions
```

Leave Pull Request creation to the user.
