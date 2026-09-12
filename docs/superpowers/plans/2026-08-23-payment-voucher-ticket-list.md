# Payment Voucher Ticket List Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken horizontal voucher rail with a full-width vertical list of horizontal ticket-style voucher cards matching the supplied reference image.

**Architecture:** Keep the existing voucher response, eligibility calculations, claim endpoint, and event handlers. Move the suggestion panel outside the input-only wrapper so it spans the complete code-input/Apply row, render each suggestion as a responsive three-area grid, and use vertical overflow for multiple vouchers.

**Tech Stack:** React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Modify only the payment-page suggestion layout, its source-level UI contract test, the design/plan documentation, and generated `public/build` output.
- Do not change voucher eligibility, claim authorization, API payloads, pricing calculations, or checkout totals.
- Keep the existing `role="listbox"`, `role="option"`, keyboard handling, claim states, CSRF behavior, and voucher handlers.
- Use a full-width vertical suggestion list; do not use a horizontal rail, fixed-width cards, `snap-x`, or horizontal overflow.
- Match the reference composition with a left voucher mark/category panel, center offer details, and right-side action.
- Keep interactive controls at least 44px high, wrap offer copy safely, and preserve visible focus states.
- Keep the neutral SoleSpace palette and use the existing semantic sale/success/amber states; add no dependency or image asset.

---

### Task 1: Replace the obsolete rail assertions with a ticket-list contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts:30-52`

**Interfaces:**
- Consumes: The current `payment.tsx` source-contract test and voucher behavior markers.
- Produces: A regression contract for a wide, vertically scrollable panel and responsive ticket cards.

- [x] **Step 1: Replace the rail assertions with ticket-list assertions.**

In the existing test, remove the old `max-h-[15rem]`, `overflow-x-auto`, `snap-x`, `min-w-[250px]`, and `snap-start` expectations. Add these assertions after the existing listbox assertions:

```ts
expect(paymentSource).toContain('max-h-[min(32rem,calc(100vh-12rem))]');
expect(paymentSource).toContain('left-0 right-0 top-full');
expect(paymentSource).toContain('overflow-y-auto');
expect(paymentSource).toContain('grid-cols-[5.5rem_minmax(0,1fr)_auto]');
expect(paymentSource).toContain('border-r border-dashed');
expect(paymentSource).toContain('Add ${formatVoucherMoney(remainingSpend)} more to unlock this voucher.');
expect(paymentSource).not.toContain('overflow-x-auto');
expect(paymentSource).not.toContain('snap-x');
expect(paymentSource).not.toContain('min-w-[250px]');
```

These checks cover the layout failure shown in the screenshot: the panel must anchor to the complete input row, scroll vertically, and never keep a clipped fixed-width card.

- [x] **Step 2: Run the focused test and verify it fails for the old rail.**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
```

Expected: FAIL because the current source still contains the horizontal rail classes and does not contain the new ticket-list markers.

### Task 2: Implement the reference-style vertical ticket list

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:148-161,3359-3510`

**Interfaces:**
- Consumes: `filteredVoucherCodeSuggestions`, `handleUseVoucher`, `handleClaimVoucher`, `voucherClaimStatusLabel`, `voucherEligibilityClass`, and the current voucher metadata.
- Produces: A complete-width `role="listbox"` with vertically stacked responsive ticket cards and unchanged actions.

- [x] **Step 1: Make the money display match the reference and calculate remaining spend once.**

Change the voucher money helper to use the Philippine peso sign without changing numeric rounding:

```ts
const formatVoucherMoney = (value: number): string => `₱${toFiniteNumber(value).toLocaleString(undefined, {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})}`;
```

Inside the voucher map, add the remaining-spend value beside `eligibleSubtotal`:

```ts
const eligibleSubtotal = toFiniteNumber(voucher.eligible_subtotal);
const remainingSpend = toFiniteNumber(voucher.remaining_spend);
```

- [x] **Step 2: Move the outside-click ref to the full input/Apply row.**

Replace the current nested input wrapper with this structure. Keep the existing input props and event handlers unchanged; only move the ref and place the Apply button inside the relative wrapper:

```tsx
<div ref={voucherInputContainerRef} className="relative">
  <div className="flex items-center gap-2">
    <input
      type="text"
      aria-label="Voucher code"
      aria-expanded={showVoucherSuggestionDropdown}
      value={voucherCodeInput}
      // Keep the existing focus, click, change, keydown, and text-hint props.
      className="min-w-0 flex-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-black"
    />
    <button
      type="button"
      onClick={handleApplyVoucherCode}
      className="min-h-11 shrink-0 rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black"
    >
      Apply
    </button>
  </div>
  {/* The suggestion panel stays here so it spans the input and Apply button. */}
</div>
```

Do not leave the Apply button outside this relative wrapper; that is what caused the suggestion surface to size against only the input area.

- [x] **Step 3: Replace the horizontal panel with bounded vertical overflow.**

Keep `role="listbox"`, `aria-label`, Escape handling, and the filtered map. Use this panel class and wrap the mapped cards in `space-y-2`:

```tsx
<div
  role="listbox"
  aria-label="Voucher suggestions"
  onKeyDown={(event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      setIsVoucherSuggestionOpen(false);
    }
  }}
  className="absolute left-0 right-0 top-full z-30 mt-2 max-h-[min(32rem,calc(100vh-12rem))] overflow-y-auto rounded-xl border border-gray-200 bg-white p-2 shadow-md"
>
  <div className="space-y-2">
    {/* filteredVoucherCodeSuggestions.map(...) */}
  </div>
</div>
```

The empty state remains inside the same panel and must still render `No matching vouchers`.

- [x] **Step 4: Render every voucher as a responsive ticket card.**

Replace the fixed-width card contents with this three-area structure while preserving the current `key`, `role`, `tabIndex`, `aria-selected`, `aria-disabled`, `onKeyDown`, selected-state classes, and all existing action handlers:

```tsx
<div
  className={`relative grid grid-cols-[5.5rem_minmax(0,1fr)_auto] items-stretch overflow-hidden rounded-lg border bg-white transition-colors sm:grid-cols-[9rem_minmax(0,1fr)_8rem] ${
    selectedVoucherCampaignId === voucher.id
      ? 'border-gray-900 bg-gray-50'
      : 'border-gray-200 hover:border-gray-400'
  }`}
>
  <div className="flex min-h-[10rem] flex-col items-center justify-center border-r border-dashed border-gray-200 bg-gray-50 px-2 py-3 text-center sm:min-h-[11rem] sm:px-3">
    <div className="flex h-14 w-14 items-center justify-center rounded-full border border-gray-200 bg-white text-xl font-semibold text-gray-900 sm:h-20 sm:w-20 sm:text-2xl" aria-hidden="true">
      %
    </div>
    <span className="mt-2 rounded-md bg-[#d30005] px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white sm:text-xs">
      {voucher.target === 'shipping' ? 'Shipping' : 'Shop'}
    </span>
  </div>

  <div className="min-w-0 px-3 py-3 sm:px-5 sm:py-4">
    {/* Keep the current name, code, and claim-status row. */}
    <p className="mt-2 text-lg font-semibold leading-tight text-gray-900 sm:text-2xl">{formatVoucherBenefit(voucher)}</p>
    {minimumSpend > 0 && (
      <>
        <p className="mt-1 text-sm text-gray-700">Min. spend {formatVoucherMoney(minimumSpend)}</p>
        <div className="mt-2 rounded-md bg-gray-50 px-2.5 py-2">
          {/* Keep the current progress calculation and render eligible amount plus progress. */}
        </div>
        <p className={`mt-2 text-xs font-medium ${remainingSpend > 0 ? 'text-amber-700' : 'text-emerald-700'}`}>
          {remainingSpend > 0 ? `Add ${formatVoucherMoney(remainingSpend)} more to unlock this voucher.` : 'Eligible for this order.'}
        </p>
      </>
    )}
    <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
      <span>{voucher.target === 'shipping' ? 'Shipping' : 'Items'}</span>
      <span aria-hidden="true">•</span>
      <span>{voucher.scope === 'shop_wide' ? 'Shop-wide' : 'Selected products'}</span>
      <span aria-hidden="true">•</span>
      <span>T&amp;C apply</span>
    </div>
    <p className={`mt-2 text-xs font-medium ${voucherEligibilityClass(voucher.eligibility)}`}>
      {voucher.eligibility_message}
    </p>
  </div>

  <div className="flex min-w-0 flex-col items-stretch justify-center gap-2 border-l border-gray-100 px-2 py-3 sm:items-end sm:px-4">
    {/* Keep the existing Claiming, Use voucher, Claim & use, Claim for later, and unavailable branches. */}
  </div>
</div>
```

The offer panel must retain the current display name/code and claim-status badge above the benefit line. The progress track remains `aria-hidden`, while the text beside it provides the accessible eligible amount and remaining-spend explanation. Use `min-h-11`, `w-full sm:w-auto`, `whitespace-nowrap`, and visible focus rings on all action buttons so the right column cannot clip their labels.

- [x] **Step 5: Run the focused test and inspect the diff.**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
git diff --check
```

Expected: all focused integration tests pass and the diff check has no output.

### Task 3: Rebuild, verify, and publish the corrected UI

**Files:**
- Modify: `docs/superpowers/plans/2026-08-23-payment-voucher-ticket-list.md`
- Generate: `public/build/*`

**Interfaces:**
- Consumes: The final ticket-list payment page.
- Produces: Fresh hashed assets and a pushed feature branch ready for the user’s PR.

- [x] **Step 1: Run the complete frontend suite.**

Run:

```powershell
node_modules/.bin/vitest.cmd run
```

Expected: every frontend test file and test passes.

- [x] **Step 2: Generate a fresh production build.**

Run:

```powershell
node_modules/.bin/vite.cmd build
```

Expected: Vite completes successfully and updates `public/build/manifest.json` plus the hashed payment asset.

- [x] **Step 3: Mark the plan complete and verify repository hygiene.**

Mark the completed checkboxes in this plan, then run:

```powershell
git diff --check
git status --short --branch
```

Expected: no whitespace errors and only the intended source, docs, and build changes are present.

- [x] **Step 4: Commit and push the correction.**

Run:

```powershell
git add resources/js/Pages/UserSide/Orders/payment.tsx resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts docs/superpowers/plans/2026-08-23-payment-voucher-ticket-list.md public/build
git commit -m "fix: match payment voucher ticket layout"
git push
```

Leave PR creation to the user.
