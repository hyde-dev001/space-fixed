# Payment Voucher Layout Design

**Date:** 2026-08-23

## Goal

Make the desktop voucher picker on the payment page compact and Shopee-like, place it directly below the Phone field, and keep it the same width as that payment-form field without changing voucher pricing, checkout, or payment behavior.

## Current root cause

The previous desktop voucher picker was rendered as a large full-width panel above the checkout grid, which made it visually dominant and disconnected from the contact and delivery fields. The backend already returns the voucher details needed by the existing UI, and the current selection/apply state is already connected to the promo preview and order payload.

## Approved design

1. Render one desktop-only voucher section inside the left payment form, directly below the Phone field and before delivery-address persistence content.
2. Keep the section `w-full` within the same form column as Phone so the voucher control and Phone input share the same width.
3. Keep the existing voucher input, Apply action, Clear action, suggestion filtering, selected campaign ID, applied code, error message, and loading state. Only their layout and visual classes change.
4. Present each available suggestion as a compact horizontal voucher card: a small fixed left badge/identity area, a flexible details area for name/code/discount/minimum spend, and a compact right-side selection action. The card uses the available `AvailableVoucherOption` fields and does not invent expiry, logo, or maximum-discount data that the API does not provide.
5. Keep the existing payment form and sticky order summary behavior unchanged. The summary continues to display the computed voucher discount.

## Interaction and data flow

- Focusing or clicking the voucher input opens the existing filtered suggestion list.
- Selecting a card sets `isVoucherSelectionEnabled`, `selectedVoucherCampaignId`, `voucherCodeInput`, and `appliedVoucherCode` through the same state updates currently used by the dropdown.
- Applying a manually typed code continues to call `handleApplyVoucherCode`.
- Clearing a selection continues to call `handleClearVoucherSelection`.
- The existing `/api/checkout/promo-preview` request and `/api/checkout/create-order` payload remain unchanged.
- Existing invalid-voucher, empty-state, and loading messages remain visible in the new section.

## Responsive behavior

- The compact section is rendered only in the existing desktop branch (`xl` and above), avoiding changes to the mobile checkout flow.
- The voucher input uses a 48px control row with an Apply button that remains reachable and keyboard accessible.
- Cards use a compact internal layout with a `4.5rem` identity rail, flexible details, and a `7rem` action rail. Long names/codes may wrap, and no fixed viewport width or horizontal scrolling is introduced.

## Accessibility and safety

- Keep the input label, button semantics, keyboard Enter/Escape behavior, focus behavior, and existing visible error text.
- Preserve visible focus states and sufficient touch/click target sizes for card selection and Apply/Clear actions.
- Change only `resources/js/Pages/UserSide/Orders/payment.tsx` for the feature UI, plus this design/implementation documentation. Do not modify controllers, services, routes, database schema, payment gateway code, or unrelated working-tree files.

## Acceptance criteria

- On desktop, the voucher section appears directly below Phone and is the same width as the Phone input.
- The compact card structure visually follows Shopee-like proportions: separated left identity area, flexible details area, and right action area.
- The old oversized full-width top panel is not rendered, and cards do not use the previous `min-h-[20rem]`/wide rail sizing.
- Voucher search/filter, selection, manual Apply, Clear, loading, empty, and error states still work.
- The promo preview request and checkout payload retain their current voucher fields and behavior.
- Mobile layout remains unchanged.
- Focused frontend tests, the frontend build, and `git diff --check` pass.
