# Payment Voucher Suggestion Rail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the payment-page voucher suggestions compact and horizontal so many item and logistics vouchers do not create a tall dropdown.

**Architecture:** Keep the existing server response, claim flow, eligibility copy, and voucher application state unchanged. Replace only the suggestion panel layout in `payment.tsx` with a bounded horizontal snap rail: fixed-width cards on wider screens and touch-scrollable cards on narrow screens. The rail preserves the complete voucher information but reduces vertical repetition.

**Tech Stack:** React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Modify only the payment-page voucher suggestion experience and its source-level integration test.
- Do not change backend voucher pricing, claim authorization, API payloads, or checkout totals.
- Keep existing claim actions, status labels, eligibility messages, keyboard semantics, and CSRF behavior.
- Use the existing black/white/gray SoleSpace visual language; use semantic green/amber/red only for state.
- Keep interactive targets at least 44px high and preserve keyboard focus visibility.
- Keep `public/build` fresh after the change.

---

### Task 1: Lock the compact rail contract with a failing UI test

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts`

**Interfaces:**
- Consumes: Existing `payment.tsx` source contract assertions.
- Produces: Assertions that the suggestion panel is a bounded horizontal rail with snap scrolling and compact card sizing.

- [x] **Step 1: Add layout assertions that initially fail.**

Add an assertion to the existing suggestion-card test for these source markers:

```ts
expect(paymentSource).toContain('max-h-[15rem]');
expect(paymentSource).toContain('overflow-x-auto');
expect(paymentSource).toContain('snap-x');
expect(paymentSource).toContain('min-w-[250px]');
expect(paymentSource).toContain('snap-start');
```

These markers represent the bounded horizontal rail, fixed card width, and predictable touch/snap position.

- [x] **Step 2: Run the focused frontend test and verify the new assertions fail.**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
```

Expected: FAIL only because the new compact-rail class markers are not yet present.

### Task 2: Convert the dropdown to a compact horizontal voucher rail

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:3396-3510`

**Interfaces:**
- Consumes: `filteredVoucherCodeSuggestions`, `handleUseVoucher`, `handleClaimVoucher`, and the existing voucher metadata.
- Produces: A bounded `role="listbox"` suggestion rail with fixed-width cards and unchanged actions.

- [x] **Step 1: Change the panel geometry without changing its data flow.**

Keep `role="listbox"`, `aria-label`, Escape handling, and the filtered map. Replace the vertical overflow classes with:

```tsx
className="hide-scrollbar absolute z-30 mt-1 flex max-h-[15rem] w-[min(42rem,calc(100vw-2rem))] gap-2 overflow-x-auto overflow-y-hidden overscroll-x-contain rounded-xl border border-gray-200 bg-white p-2 shadow-md snap-x snap-mandatory"
```

Use `scrollbar`-safe horizontal overflow only; the panel must not grow vertically as suggestions are added.

- [x] **Step 2: Make each voucher card a compact fixed-width snap item.**

Change the card wrapper classes to include:

```tsx
className={`min-w-[250px] max-w-[250px] shrink-0 snap-start rounded-lg border px-3 py-3 text-left transition-colors focus:outline-none focus:ring-2 focus:ring-gray-900/20 ${...}`}
```

Reduce repeated vertical whitespace to `mt-2` where the card remains readable. Keep the name/status, benefit/target, minimum-spend progress, eligibility message, and action row. Keep the action buttons `min-h-11`; do not remove status or eligibility text.

- [x] **Step 3: Preserve responsive behavior and accessibility.**

Keep `aria-selected`, `aria-disabled`, keyboard activation, visible focus rings, and the existing disabled/loading states. Add `touch-pan-x` to the rail and `cursor-grab`/`active:cursor-grabbing` only to the scroll surface; never make the card itself depend on hover.

- [x] **Step 4: Run the focused frontend test and inspect the diff.**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts
git diff --check
```

Expected: 4 focused tests pass and the diff check has no output.

### Task 3: Rebuild and verify the compact rail

**Files:**
- Generated: `public/build/*`

**Interfaces:**
- Consumes: The final payment page source.
- Produces: A fresh manifest entry for the payment chunk and a clean branch ready to push.

- [x] **Step 1: Run the complete frontend suite.**

Run:

```powershell
node_modules/.bin/vitest.cmd run
```

Expected: all frontend test files and tests pass.

- [x] **Step 2: Generate the production build.**

Run:

```powershell
node_modules/.bin/vite.cmd build
```

Expected: Vite transforms the application and writes a new `public/build/manifest.json` plus hashed payment assets.

- [x] **Step 3: Commit and push the UI refinement.**

Run:

```powershell
git add resources/js/Pages/UserSide/Orders/payment.tsx resources/js/Pages/UserSide/Orders/__tests__/paymentShippingVoucherIntegration.test.ts public/build docs/superpowers/plans/2026-08-23-payment-voucher-suggestion-rail.md
git commit -m "fix: compact payment voucher suggestions"
git push
```

Leave PR creation to the user.
