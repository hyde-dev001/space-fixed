# Payment Location Layout Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorder payment location controls to Province, City/Municipality, Postal code and remove pre-selection shipping placeholder copy.

**Architecture:** Make a JSX-only change in the two existing address layouts and adjust the three existing shipping-summary derived strings. Keep every state value, handler, API request, validation path, and post-selection message unchanged.

**Tech Stack:** React 18, TypeScript 5.7, Tailwind CSS, Vite 7

## Global Constraints

- Modify only `resources/js/Pages/UserSide/Orders/payment.tsx`.
- Both address layouts must order controls as Province, City/Municipality, Postal code.
- Keep current narrow-width stacking and wide-width three-column behavior.
- Keep the Shipping row visible with a blank value before city selection.
- Remove `Select a city`, `Select a city to calculate shipping.`, and `Shipping fee will appear after you select a city.` only from the empty state.
- Preserve post-selection calculating, fee, unavailable/error, and payment-blocking behavior.
- Do not change APIs, state, validation, fee calculation, payloads, or location data.

---

### Task 1: Reorder Location Fields and Clean Shipping Empty State

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:2031-2046`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:2450-2538`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:2648-2748`

**Interfaces:**
- Consumes: existing `hasSelectedCity`, `hasShippingEstimate`, `shipping`, `isShippingEstimateLoading`, and `shippingEstimateReason` values.
- Produces: unchanged shipping behavior with a blank pre-city summary state and reordered JSX controls.

- [ ] **Step 1: Record the current UI assertions**

Run:

```powershell
rg -n "Select a city|Shipping fee will appear after you select a city|Postal code|Province|City/Municipality" resources/js/Pages/UserSide/Orders/payment.tsx
```

Expected before editing: all three shipping empty-state strings exist, and Postal code appears before Province and City/Municipality in both form blocks.

- [ ] **Step 2: Make the shipping empty state blank**

Replace the three derived expressions with:

```tsx
const shippingSummaryValue = hasSelectedCity
  ? (hasShippingEstimate ? `₱${shipping.toLocaleString()}` : (isShippingEstimateLoading ? 'Calculating...' : 'Unavailable'))
  : '';
const isShippingCalculating = hasSelectedCity && isShippingEstimateLoading;
const shippingCarrierNote = hasShippingEstimate
  ? ''
  : (hasSelectedCity ? (shippingEstimateReason || 'Complete your delivery address to calculate shipping.') : '');
const shippingPayLaterNotice = hasShippingEstimate
  ? ''
  : (hasSelectedCity ? 'Shipping fee must be calculated before you can continue to payment.' : '');
```

- [ ] **Step 3: Reorder both existing JSX groups**

Within the address-sheet `grid grid-cols-1 gap-3 sm:grid-cols-3`, move the unchanged postal-code `<input>` block after the unchanged city dropdown block. Do not alter its props.

Within the desktop `grid grid-cols-1 gap-5 lg:grid-cols-3`, move the unchanged Postal code labeled `<div>` after the unchanged City/Municipality labeled `<div>`. Update the nearby comment to:

```tsx
{/* Province, city/municipality, and postal code */}
```

The resulting sibling order in both blocks must be:

```text
Province dropdown
City/Municipality dropdown
Postal code input
```

- [ ] **Step 4: Verify the static UI conditions**

Run:

```powershell
rg -n "Select a city|Shipping fee will appear after you select a city" resources/js/Pages/UserSide/Orders/payment.tsx
```

Expected: no matches.

Inspect both three-column blocks and confirm Province precedes City/Municipality, which precedes Postal code. Confirm the post-selection strings `Calculating...`, `Unavailable`, and `Shipping fee must be calculated before you can continue to payment.` remain.

- [ ] **Step 5: Run focused test and production build**

Run:

```powershell
node_modules\.bin\vitest.cmd run resources/js/data/__tests__/philippineLocations.test.ts
node_modules\.bin\vite.cmd build --configLoader runner --logLevel error
```

Expected: 5 focused tests pass and the Vite production build exits 0.

- [ ] **Step 6: Commit when Git metadata is available**

```powershell
git add resources/js/Pages/UserSide/Orders/payment.tsx
git commit -m "style: clean payment location layout"
```

If this workspace remains outside a Git repository, skip only the commit and report that limitation.
