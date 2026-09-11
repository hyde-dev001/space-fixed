# Payment Voucher Inline Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Preserve the existing desktop voucher behavior and payment flow while making the voucher picker compact, visibly separating product/shipping suggestions, and rendering the open voucher list in normal document flow so the delivery-address, preference, and payment sections move down instead of being covered.

**Architecture:** Change only the suggestion panel positioning and presentation in the existing `Payment` component. Remove the absolute-positioning classes from the current listbox, wrap the existing cards in non-empty Product Vouchers and Shipping Vouchers groups, and use compact card spacing/dimensions while preserving the card handlers, state, payment requests, and payment computation. Update the existing source-contract tests to lock the inline-flow, grouping, and compact layout behavior.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Preserve existing voucher claim, use, clear, filtering, loading, eligibility, and keyboard behavior.
- Preserve payment preview requests, checkout payloads, discount calculations, and backend files.
- Keep the voucher cards, text, and action column compact enough to avoid unnecessary vertical bulk while retaining the existing visual language.
- Keep action buttons at the existing touch-safe minimum height while reducing their width/padding and text size where possible.
- Keep a clear vertical gap between the Product Vouchers and Shipping Vouchers groups.
- Keep the open suggestion list bounded with the existing max-height and vertical scrolling.
- Modify only the payment source, its focused layout/integration tests, this plan note, and the requested production build if regenerated.

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
