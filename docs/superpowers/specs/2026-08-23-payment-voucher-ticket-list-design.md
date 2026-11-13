# Payment Voucher Ticket List Design

## Goal

Make payment-page voucher suggestions look like a full-width voucher list: each suggestion is a horizontal ticket card with a branded left panel, offer details in the middle, and a clear claim/use action on the right.

## User-facing behavior

- Opening the voucher input shows a vertically stacked list of all matching item and logistics vouchers.
- Each card keeps the existing voucher name/code, claim state, benefit, target, minimum-spend requirement, eligibility message, and claim/use action.
- A minimum-spend voucher shows the eligible amount, progress, and the amount still needed when the order is below the threshold.
- Claiming, using, clearing, and applying a voucher continue to use the existing handlers and backend payloads.
- The list scrolls vertically inside a bounded suggestion panel so many vouchers do not push the checkout layout off-screen.

## Visual structure

1. **Suggestion panel:** full width of the voucher input row, white surface, thin border, restrained radius, vertical overflow only.
2. **Ticket card:** full available width, flat border, three-area horizontal split, no fixed minimum width.
3. **Left panel:** centered generic SoleSpace voucher mark and target label, separated with a dashed divider. No new logo or backend field is introduced.
4. **Offer panel:** large benefit line, minimum-spend line, progress bar, claim/eligibility status, and compact terms-style metadata.
5. **Action panel:** right-aligned `Claim`, `Claim & use`, `Claim for later`, or `Use voucher` button; disabled/loading states remain explicit.

## Responsive behavior

- Desktop and tablet keep the three visual areas in one horizontal row.
- Narrow screens reduce the left and action columns while allowing the offer copy to wrap; no horizontal page or card scrolling is introduced.
- Every button keeps a minimum 44px height and visible keyboard focus.
- The suggestion panel uses vertical scrolling and a viewport-aware maximum height.

## Scope and constraints

- Change only the payment-page suggestion layout, its source-level UI contract test, the implementation plan/spec documentation, and generated `public/build` output.
- Do not change voucher eligibility, claim authorization, pricing calculations, API responses, or checkout totals.
- Reuse the existing neutral SoleSpace palette and semantic green/amber/red states. Do not add a dependency or a new image asset.

## Acceptance criteria

- The rendered suggestions match the reference composition: stacked full-width cards with horizontal ticket content and a right-side action.
- Adding multiple suggestions increases the list vertically, not by creating clipped fixed-width cards or a horizontal carousel.
- The voucher input and `Apply` button remain usable when the suggestion panel is open.
- Existing focused voucher integration tests and the full frontend suite pass.
- `public/build` is freshly generated and the feature branch is pushed.
