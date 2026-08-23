# Payment Voucher Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Move the desktop voucher picker out of the narrow order-summary sidebar into a full-width card layout that matches the supplied reference proportions while preserving all voucher and payment behavior.

**Architecture:** Keep the existing `Payment` component state, handlers, promo-preview request, and create-order payload unchanged. Replace only the desktop voucher JSX: render one full-width section before the desktop checkout grid, then remove the old sidebar form. Use the existing `AvailableVoucherOption` fields for wide horizontal suggestion cards.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest 3, pnpm.

## Global Constraints

- Modify only `resources/js/Pages/UserSide/Orders/payment.tsx` and add the focused frontend regression test; do not change backend, routes, database, payment gateway, dependencies, or unrelated working-tree files.
- Preserve `selectedVoucherCampaignId`, `voucherCodeInput`, `appliedVoucherCode`, `isVoucherSelectionEnabled`, `handleApplyVoucherCode`, and `handleClearVoucherSelection` behavior.
- Keep the new voucher section desktop-only (`hidden xl:block`) so the existing mobile checkout branch is unchanged.
- Use existing Tailwind classes and existing API fields; do not add a package or invent expiry, logo, usage, or maximum-discount data.
- Verify with the focused Vitest test, `pnpm run build`, and `git diff --check`.

## File Map

- Create: `resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts` — source-level regression checks for placement, width-oriented card structure, preserved handlers, and removal from the sidebar.
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:2802-3300` — move and restyle the desktop voucher picker only.
- Create: `docs/superpowers/specs/2026-08-23-payment-voucher-layout-design.md` — approved design, already committed as `20026389a`.

### Task 1: Add the failing layout regression test

**Files:**
- Create: `resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts`

**Interfaces:**
- Consumes: the current source text of `resources/js/Pages/UserSide/Orders/payment.tsx`.
- Produces: a failing contract for the full-width desktop section and card layout that the implementation must satisfy.

- [ ] **Step 1: Write the failing test**

Create the test with the following content:

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment desktop voucher layout', () => {
  it('places the voucher picker before the desktop checkout grid at full width', () => {
    const voucherSectionIndex = paymentSource.indexOf('data-testid="desktop-voucher-section"');
    const desktopGridIndex = paymentSource.indexOf('<div className="hidden xl:grid grid-cols-1 md:grid-cols-3 gap-6 items-start">');

    expect(voucherSectionIndex).toBeGreaterThan(-1);
    expect(desktopGridIndex).toBeGreaterThan(voucherSectionIndex);

    const desktopVoucherSection = paymentSource.slice(voucherSectionIndex, desktopGridIndex);
    expect(desktopVoucherSection).toContain('data-testid="desktop-voucher-suggestions"');
    expect(desktopVoucherSection).toContain('absolute left-0 right-0 top-full');
    expect(desktopVoucherSection).toContain('handleApplyVoucherCode');
    expect(desktopVoucherSection).toContain('handleClearVoucherSelection');
    expect(desktopVoucherSection).toContain('data-testid="voucher-suggestion-card"');
  });

  it('does not keep the voucher input inside the narrow order-summary sidebar', () => {
    const summaryIndex = paymentSource.indexOf('{/* Right: Order Summary (sticky on md) */}');
    const summarySource = paymentSource.slice(summaryIndex);

    expect(summaryIndex).toBeGreaterThan(-1);
    expect(summarySource).not.toContain('aria-label="Voucher code"');
  });
});
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts
```

Expected result: the test command exits non-zero because the current `payment.tsx` does not yet contain `data-testid="desktop-voucher-section"`.

### Task 2: Move and restyle the desktop voucher picker

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:2802-3300`

**Interfaces:**
- Consumes: existing promo preview state, voucher filtering values, and voucher event handlers in `Payment`.
- Produces: one desktop full-width voucher input/card section before the desktop checkout grid; the existing order summary retains only calculated totals and discount display.

- [ ] **Step 1: Add the full-width desktop section before the checkout grid**

Immediately before the existing `hidden xl:grid` desktop checkout wrapper, add this JSX structure. Keep the existing input event handlers and state updates exactly as shown so the promo flow remains unchanged:

```tsx
          <section
            data-testid="desktop-voucher-section"
            className="hidden xl:block mb-8 rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
          >
            {isPromoPreviewLoading ? (
              <div className="flex min-h-20 items-center justify-center rounded-lg bg-gray-50 px-4 py-6">
                <p className="text-sm text-gray-600">Checking claimed vouchers...</p>
              </div>
            ) : (
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-semibold uppercase tracking-wide text-gray-700">
                    Voucher
                  </label>
                  <p className="mt-1 text-sm text-gray-600">Type a voucher code or choose from suggestions.</p>
                </div>

                <div className="flex items-stretch gap-3">
                  <div ref={voucherInputContainerRef} className="relative min-w-0 flex-1">
                    <input
                      type="text"
                      aria-label="Voucher code"
                      value={voucherCodeInput}
                      onFocus={() => setIsVoucherSuggestionOpen(true)}
                      onClick={() => setIsVoucherSuggestionOpen(true)}
                      onChange={(e) => {
                        const nextVoucherCode = e.target.value.toUpperCase();
                        const normalizedNextVoucherCode = normalizeVoucherCode(nextVoucherCode);

                        setSelectedVoucherCampaignId(null);
                        setHasVoucherInputInteraction(true);
                        setVoucherCodeInput(nextVoucherCode);

                        if (normalizedNextVoucherCode === '') {
                          setAppliedVoucherCode('');
                          setIsVoucherSelectionEnabled(false);
                        }

                        setIsVoucherSuggestionOpen(true);
                      }}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          e.preventDefault();
                          handleApplyVoucherCode();
                        }

                        if (e.key === 'Escape') {
                          setIsVoucherSuggestionOpen(false);
                        }
                      }}
                      placeholder="Enter voucher code"
                      className="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-base text-black shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                    />

                    {showVoucherSuggestionDropdown && (
                      <div
                        data-testid="desktop-voucher-suggestions"
                        className="hide-scrollbar absolute left-0 right-0 top-full z-40 mt-3 max-h-[70vh] overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 shadow-xl"
                      >
                        {filteredVoucherCodeSuggestions.length > 0 ? (
                          <div className="space-y-4">
                            {filteredVoucherCodeSuggestions.map((voucher) => {
                              const displayName = voucher.name || voucher.code || 'Voucher';
                              const displayCode = normalizeVoucherCode(String(voucher.code || voucher.name || ''));
                              const discountLabel = voucher.discount_mode === 'percentage'
                                ? `${voucher.value.toLocaleString()}% off`
                                : `₱${voucher.value.toLocaleString()} off`;

                              return (
                                <button
                                  key={voucher.id}
                                  data-testid="voucher-suggestion-card"
                                  type="button"
                                  onMouseDown={(e) => e.preventDefault()}
                                  onClick={() => {
                                    const normalizedCode = normalizeVoucherCode(displayCode);
                                    setIsVoucherSelectionEnabled(true);
                                    setSelectedVoucherCampaignId(voucher.id);
                                    setHasVoucherInputInteraction(true);
                                    setVoucherCodeInput(normalizedCode);
                                    setAppliedVoucherCode(normalizedCode);
                                    setIsVoucherSuggestionOpen(false);
                                  }}
                                  className="group flex min-h-44 w-full overflow-hidden rounded-xl border border-gray-200 bg-white text-left shadow-sm transition hover:border-gray-400 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-gray-900"
                                >
                                  <span className="flex w-32 shrink-0 flex-col items-center justify-center border-r border-dashed border-gray-300 bg-gray-50 px-4 text-center">
                                    <span className="flex h-20 w-20 items-center justify-center rounded-full border border-gray-200 bg-white text-3xl font-bold text-gray-900">
                                      %
                                    </span>
                                    <span className="mt-3 rounded-md bg-gray-900 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                                      Voucher
                                    </span>
                                  </span>

                                  <span className="min-w-0 flex-1 px-5 py-5">
                                    <span className="block text-base font-semibold text-gray-900">{displayName}</span>
                                    {voucher.code && voucher.name && normalizeVoucherCode(voucher.code) !== normalizeVoucherCode(voucher.name) && (
                                      <span className="mt-1 block text-sm font-medium text-gray-500">{displayCode}</span>
                                    )}
                                    <span className="mt-4 block text-2xl font-bold leading-tight text-gray-900">{discountLabel}</span>
                                    <span className="mt-2 block text-base text-gray-700">
                                      Min. spend ₱{voucher.min_spend.toLocaleString()}
                                    </span>
                                    <span className="mt-4 block border-t border-dashed border-gray-200 pt-3 text-sm text-gray-500">
                                      Available voucher
                                    </span>
                                  </span>

                                  <span className="flex w-36 shrink-0 items-center justify-center border-l border-gray-200 px-4">
                                    <span className="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white transition group-hover:bg-black">
                                      Use voucher
                                    </span>
                                  </span>
                                </button>
                              );
                            })}
                          </div>
                        ) : (
                          <div className="px-3 py-6 text-center text-sm text-gray-500">No available vouchers</div>
                        )}
                      </div>
                    )}
                  </div>

                  <button
                    type="button"
                    onClick={handleApplyVoucherCode}
                    className="min-h-12 w-32 shrink-0 rounded-lg bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-black focus:outline-none focus:ring-2 focus:ring-gray-900"
                  >
                    Apply
                  </button>
                </div>

                {(selectedVoucherCampaignId !== null || appliedVoucherCode) && (
                  <button
                    type="button"
                    onClick={handleClearVoucherSelection}
                    className="text-sm font-medium text-gray-700 underline underline-offset-2 hover:text-black focus:outline-none focus:ring-2 focus:ring-gray-900"
                  >
                    Clear voucher selection
                  </button>
                )}

                {voucherErrorMessage && (
                  <p role="alert" className="text-sm font-medium text-red-600">{voucherErrorMessage}</p>
                )}
              </div>
            )}
          </section>
```

- [ ] **Step 2: Remove the old sidebar voucher block**

In the `Right: Order Summary (sticky on md)` aside, delete only the block beginning with `{/* Auto-applied voucher */}` and ending after the `voucherErrorMessage` paragraph. Leave the product items, summary, shipping messages, VAT, total, and sticky behavior unchanged. The sidebar must no longer contain `aria-label="Voucher code"`.

- [ ] **Step 3: Preserve the existing computed discount row**

Do not remove or alter the later summary row that uses `voucherDiscountAmount` and `appliedVoucherLabel`; it is the checkout total confirmation and must continue to reflect the promo preview response.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts
```

Expected result: both layout tests pass with zero failures.

### Task 3: Verify the complete frontend change

**Files:**
- Inspect: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Inspect: `resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts`

**Interfaces:**
- Consumes: the completed desktop voucher layout and its regression test.
- Produces: fresh evidence that the UI compiles, the layout contract passes, and the diff is clean.

- [ ] **Step 1: Run all frontend tests**

Run:

```powershell
pnpm run test:frontend
```

Expected result: Vitest exits with code 0 and reports zero failed tests.

- [ ] **Step 2: Build the frontend bundle**

Run:

```powershell
pnpm run build
```

Expected result: Vite exits with code 0 and emits the production bundle.

- [ ] **Step 3: Check diff hygiene**

Run:

```powershell
git diff --check
```

Expected result: no whitespace errors. Confirm `git status --short` still shows the user's pre-existing logistics/package/test changes untouched.

- [ ] **Step 4: Review the changed area**

Check that:

1. The only production behavior change is desktop voucher placement and styling.
2. The existing promo request still includes `voucher_campaign_id`, `voucher_code`, and `disable_voucher`.
3. The checkout request still includes the same voucher fields.
4. No unused imports, handlers, or state variables were created.
5. No hard-coded unavailable voucher metadata was added.

- [ ] **Step 5: Commit the implementation**

After all checks pass, stage only the voucher test and `payment.tsx`, then commit:

```powershell
git add -- resources/js/Pages/UserSide/Orders/payment.tsx resources/js/Pages/UserSide/Orders/__tests__/paymentVoucherLayout.test.ts
git commit -m "fix: widen desktop payment voucher picker"
```

The existing unrelated working-tree changes must remain unstaged and uncommitted.
