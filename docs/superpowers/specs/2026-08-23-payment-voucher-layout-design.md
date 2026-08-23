# Payment Voucher Layout Design

**Date:** 2026-08-23

## Goal

Make the desktop voucher picker on the payment page match the full-width card proportions shown in the supplied reference image, without changing voucher pricing, checkout, or payment behavior.

## Current root cause

The voucher picker is rendered inside the desktop order-summary `<aside>`, which occupies one column of the `md:grid-cols-3` layout. Its suggestion dropdown uses `w-full`, so its maximum width is the narrow sidebar rather than the payment content container. The backend already returns the voucher details needed by the existing UI, and the current selection/apply state is already connected to the promo preview and order payload.

## Approved design

1. Render one desktop-only voucher section at the top of the existing desktop checkout container, before the two-column payment/order layout.
2. Remove the voucher picker markup from the narrow order-summary sidebar so there is one source of truth and no duplicate controls.
3. Keep the existing voucher input, Apply action, Clear action, suggestion filtering, selected campaign ID, applied code, error message, and loading state. Only their layout and visual classes change.
4. Present each available suggestion as a wide horizontal voucher card: a fixed left badge/identity area, a flexible details area for name/code/discount/minimum spend, and a right-side selection action. The card uses the available `AvailableVoucherOption` fields and does not invent expiry, logo, or maximum-discount data that the API does not provide.
5. Keep the existing payment form and sticky order summary below the voucher section. The summary continues to display the computed voucher discount.

## Interaction and data flow

- Focusing or clicking the voucher input opens the existing filtered suggestion list.
- Selecting a card sets `isVoucherSelectionEnabled`, `selectedVoucherCampaignId`, `voucherCodeInput`, and `appliedVoucherCode` through the same state updates currently used by the dropdown.
- Applying a manually typed code continues to call `handleApplyVoucherCode`.
- Clearing a selection continues to call `handleClearVoucherSelection`.
- The existing `/api/checkout/promo-preview` request and `/api/checkout/create-order` payload remain unchanged.
- Existing invalid-voucher, empty-state, and loading messages remain visible in the new section.

## Responsive behavior

- The new full-width section is rendered only in the existing desktop branch (`xl` and above), matching the reference image and avoiding changes to the mobile checkout flow.
- The desktop voucher input uses a full-width input row with an Apply button that remains reachable and keyboard accessible.
- Cards use a responsive internal layout: the detail area may wrap long names/codes, while the action area remains visible. No fixed viewport width or horizontal scrolling is introduced.

## Accessibility and safety

- Keep the input label, button semantics, keyboard Enter/Escape behavior, focus behavior, and existing visible error text.
- Preserve visible focus states and sufficient touch/click target sizes for card selection and Apply/Clear actions.
- Change only `resources/js/Pages/UserSide/Orders/payment.tsx` for the feature UI, plus this design/implementation documentation. Do not modify controllers, services, routes, database schema, payment gateway code, or unrelated working-tree files.

## Acceptance criteria

- On desktop, the voucher input and suggestion cards span the payment content area instead of the narrow order-summary column.
- The card structure visually follows the reference proportions: separated left identity area, wide details area, and right action area.
- Voucher search/filter, selection, manual Apply, Clear, loading, empty, and error states still work.
- The promo preview request and checkout payload retain their current voucher fields and behavior.
- Mobile layout remains unchanged.
- Focused frontend tests, the frontend build, and `git diff --check` pass.
