# Payment Voucher Suggestions Design

**Date:** 2026-08-23  
**Status:** Approved for implementation

## Goal

Make the voucher suggestions on the customer payment page understandable and actionable. Each suggestion must explain the benefit, target, current-order eligibility, minimum-spend progress, and whether the customer has claimed or already used it. A claimable voucher can be claimed and immediately applied with one action.

## Scope

In scope:

- Enrich the existing checkout promo-preview voucher suggestion payload.
- Show richer, accessible voucher cards inside the existing payment-page suggestion dropdown.
- Add the `Claim & use` interaction for claimable vouchers that are eligible for the current order.
- Show clear disabled states for already-used, unavailable, or currently ineligible vouchers.
- Keep shipping eligibility authoritative to the existing Shop-owned Logistics rules.

Out of scope:

- Changes to the shop-owner voucher management page.
- Changes to order totals, voucher redemption, or checkout pricing rules.
- New database tables or migrations.
- A separate voucher page or a global voucher drawer.

## UX behavior

The existing code input and `Apply` button remain at the top. Focusing the input opens a scrollable suggestion panel. Each suggestion is a compact card with:

1. Campaign name and normalized voucher code.
2. Benefit text such as `50% off shipping` or `PHP 100 off items`.
3. Target and scope context (`Shipping`, `Items`, `Shop-wide`, or `Selected products`).
4. Minimum spend and, when unmet, the remaining amount required.
5. An eligibility message derived from the current cart and delivery context.
6. A status badge (`Claimed`, `Available to claim`, `Already used`, or `Unavailable`).
7. One action: `Use voucher`, `Claim & use`, `Claim for later`, or a disabled reason.

Cards use the existing neutral payment-page styling from `DESIGN.md`: white surfaces, black primary action, light gray borders, emerald only for positive eligibility/savings, amber for requirements, and red only for errors. Touch targets remain at least 44px and the panel exposes expanded/listbox semantics for keyboard and assistive-technology users.

When a claimable voucher is eligible, `Claim & use` posts to the existing authenticated claim route using a cart product ID supplied by the server. On success the card becomes claimed, the campaign ID and code are selected, and the existing promo preview refresh applies the discount. If the voucher is claimable but not eligible for the current order, `Claim for later` claims it without pretending that it is applied.

## Backend contract

The existing `available_vouchers` and `voucher_code_suggestions` arrays keep their names and existing fields. Each item additionally returns:

```text
scope: shop_wide | product_specific
claim_status: claimed | claimable | redeemed | unavailable
eligibility: eligible | minimum_spend | not_applicable | shipping_unavailable | shipping_fee_required | unavailable
eligibility_message: string
eligible_subtotal: number
remaining_spend: number
claim_product_id: number | null
can_claim: boolean
```

The controller computes these values from the authenticated customer, server-loaded products, sale-adjusted pricing line items, current shipping fee/address, and the existing claim records. It does not trust client-provided eligibility or claim state.

Shipping suggestion eligibility calls the existing `ShippingVoucherService` with the current raw shipping context. That keeps the “own logistics only” rule, coverage radius, pinned-address requirement, minimum spend, and shipping-fee requirement consistent with applying the voucher.

The existing product-specific claim endpoint remains the only mutation path. The server returns a matching cart product ID so the frontend never guesses which product is authorized for the claim.

## Error handling

- Claim requests include the current CSRF token, credentials, and an `Accept: application/json` header.
- A 409 or validation response refreshes the promo preview and shows the server message without applying a stale voucher.
- A network failure leaves the suggestion panel open and shows a concise inline error.
- The existing manual code path and totals remain unchanged.

## Verification

- Feature tests verify claimed, claimable, redeemed, minimum-spend, item-scope, and shipping-coverage metadata.
- Frontend source tests verify the typed response contract, card content, accessible listbox semantics, and claim request path.
- Run the focused Laravel and frontend tests, `pnpm run build`, `git diff --check`, and the repository pre-push checks before pushing.
