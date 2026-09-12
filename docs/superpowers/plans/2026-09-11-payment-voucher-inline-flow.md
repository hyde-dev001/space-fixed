# Payment Voucher Inline Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Preserve the existing desktop voucher behavior and payment flow while making the voucher picker compact, visibly separating product/shipping suggestions, rendering the open voucher list in normal document flow, and allowing one product voucher plus one shipping voucher to apply together.

**Architecture:** Keep the existing `Payment` component and checkout controller flow. The frontend tracks one selected campaign per voucher target and submits the selected campaign IDs/codes together. The controller resolves those campaigns against the authenticated customer's shop-scoped voucher set, sends only the product voucher to the existing item-pricing service, and sends only the shipping voucher to the existing shipping service. Preserve the legacy scalar request/response fields for existing clients while adding a plural applied-voucher summary.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Preserve existing voucher claim, use, clear, filtering, loading, eligibility, and keyboard behavior.
- Preserve payment preview requests, checkout payloads, discount calculations, and backend files.
- Keep the voucher cards, text, and action column compact enough to avoid unnecessary vertical bulk while retaining the existing visual language.
- Keep action buttons at the existing touch-safe minimum height while reducing their width/padding and text size where possible.
- Keep a clear vertical gap between the Product Vouchers and Shipping Vouchers groups.
- Allow at most one selected voucher per target, while retaining the other target when a customer changes a selection.
- Apply an eligible product voucher to the item subtotal and an eligible shipping voucher to the raw shipping fee in the same preview/order.
- Preserve customer/shop authorization and the existing Shop-owned Logistics eligibility rule for shipping vouchers for both individual and company shop records.
- Keep the open suggestion list bounded with the existing max-height and vertical scrolling.
- Modify only the payment source, its focused layout/integration tests, this plan note, and the requested production build if regenerated.
- Add the smallest focused checkout feature coverage needed for combined selection and redemption.

---

### Task 1: Lock normal-flow behavior in focused tests

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts`
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts`

**Interfaces:**
- Consumes: the source text of `resources/js/Pages/UserSide/Orders/payment.tsx`.
- Produces: regression checks that the open listbox is an in-flow block and is not absolutely positioned.

- [x] **Step 1: Replace overlay assertions.**

In both focused tests, replace assertions that require `absolute left-0 right-0 top-full` with assertions that require the existing bounded vertical list classes and explicitly reject the overlay marker:

```ts
expect(desktopVoucherSection).toContain('hide-scrollbar mt-1 max-h-[min(20rem,calc(100vh-12rem))]');
expect(desktopVoucherSection).toContain('overflow-y-auto');
expect(desktopVoucherSection).not.toContain('absolute left-0 right-0 top-full');
```

Keep all existing assertions for voucher handlers, card dimensions, action controls, keyboard semantics, and payment integration.

- [x] **Step 2: Run the focused tests and verify RED.**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
```

Expected: the focused tests fail only because the current suggestion list still contains the absolute-positioning class string.

### Task 2: Render the voucher suggestions in normal flow

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:3266-3412`

**Interfaces:**
- Consumes: `showVoucherSuggestionDropdown`, `filteredVoucherCodeSuggestions`, `handleUseVoucher`, `handleClaimVoucher`, and the existing voucher state.
- Produces: the same `role="listbox"` and voucher cards rendered below the code input in normal document flow.

- [x] **Step 1: Remove only the overlay positioning classes.**

Change the listbox class from:

```tsx
className="hide-scrollbar absolute left-0 right-0 top-full z-40 mt-1 max-h-[min(20rem,calc(100vh-12rem))] overflow-y-auto rounded-xl border border-[#cacacb] bg-white p-1 shadow-none"
```

to:

```tsx
className="hide-scrollbar mt-1 max-h-[min(20rem,calc(100vh-12rem))] overflow-y-auto rounded-xl border border-[#cacacb] bg-white p-1 shadow-none"
```

Do not change the surrounding `relative` input wrapper or any child card markup. This keeps the panel at the input width, preserves the current UI sizing, and makes the panel occupy layout space when open.

- [x] **Step 2: Run the focused tests and verify GREEN.**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
```

Expected: all focused voucher layout/integration tests pass.

### Task 3: Verify the final frontend output

**Files:**
- Inspect: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Inspect: focused voucher tests
- Generate: `public/build/*`

**Interfaces:**
- Consumes: the normal-flow voucher list implementation.
- Produces: passing frontend verification and a fresh production bundle with the inline layout.

- [x] **Step 1: Run the complete frontend suite.**

Run:

```powershell
node_modules/.bin/vitest.cmd run
```

Expected: Vitest exits with code 0 and reports zero failed tests.

- [x] **Step 2: Build the production assets.**

Run:

```powershell
node_modules/.bin/vite.cmd build
```

Expected: Vite exits with code 0 and writes the updated manifest and hashed assets under `public/build`.

- [x] **Step 3: Check the final scope.**

Run:

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors; only the payment source/test/plan and fresh `public/build` changes are present in this isolated worktree.

- [x] **Step 4: Review behavior before handoff.**

Confirm the diff removes only `absolute`, `left-0`, `right-0`, `top-full`, and `z-40` from the suggestion panel. Confirm `handleApplyVoucherCode`, `handleUseVoucher`, `handleClaimVoucher`, `handleClearVoucherSelection`, promo preview payload fields, checkout payload fields, and summary calculations are unchanged.

### Task 4: Support one product and one shipping voucher together

**Files:**
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Modify: `tests/Feature/CheckoutPromoPricingTest.php`
- Modify: focused payment voucher integration tests

**Interfaces:**
- Consumes: existing claimed/code voucher discovery, `PromoPricingService`, `ShippingVoucherService`, and voucher claim redemption.
- Produces: target-aware selection arrays, combined preview/order totals, and redemption of both campaigns without changing the legacy scalar contract.

- [x] **Step 1: Add failing combined-selection coverage.**
- [x] **Step 2: Normalize and authorize plural voucher selections on preview and order creation.**
- [x] **Step 3: Keep item and shipping discounts independent and redeem both applied claims.**
- [x] **Step 4: Track one selection per target in the payment UI and submit both selections.**
- [x] **Step 5: Increase the product/shipping group gap and verify focused frontend tests.**
