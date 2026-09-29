# Payment Location Layout Cleanup

## Goal

Clean up the payment address and shipping-summary UI without changing location state, validation, shipping calculation, or checkout behavior.

## Address Layout

In both the address-sheet form and desktop payment form, display the three location controls in this order:

1. Province
2. City/Municipality
3. Postal code

Keep the existing responsive behavior: controls stack at narrow widths and use three columns at their current wider breakpoint. Preserve all existing labels, placeholders, dropdown behavior, accessibility attributes, field values, and event handlers.

## Shipping Summary

Keep the `Shipping` row visible at all times. Before a city/municipality is selected, render no value beside the label and no helper or pay-later message beneath it.

After a city/municipality is selected, preserve the existing behavior:

- show `Calculating...` while requesting an estimate;
- show the formatted shipping fee when available;
- show the existing unavailable/error explanation when calculation fails;
- show the existing payment-blocking notice while a required fee remains unavailable.

Remove only these empty-state strings from the rendered UI:

- `Select a city`
- `Select a city to calculate shipping.`
- `Shipping fee will appear after you select a city.`

## Scope and Verification

Modify only `resources/js/Pages/UserSide/Orders/payment.tsx`. Do not change APIs, state, validation, fee calculation, persisted payloads, or the Philippine location dataset.

Verify the control order in both form copies, confirm the three empty-state strings are absent, run the focused Philippine-location test, and run the production build.
