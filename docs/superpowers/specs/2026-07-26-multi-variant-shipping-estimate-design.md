# Multi-Variant Shipping Estimate Fix

## Problem

Cart lines for different color or size variants can share one product ID. The
payment page sends every selected line's product ID to `/api/shipping/estimate`,
so valid same-product variants produce duplicate `item_pids`. The endpoint
currently rejects duplicates with Laravel's `distinct` validation rule and
blocks checkout before shipping can be estimated.

## Design

Allow repeated integer product IDs in the shipping-estimate request. Keep the
existing array size, integer, active-product, and single-shop validation.
`ShippingEstimateController::resolveShopOwner()` already queries products with
`whereIn` and resolves unique shop-owner IDs, so repeated IDs do not change the
shipping origin or fee.

Order creation remains unchanged: each color and size variant stays as its own
order line for price, stock, and fulfillment.

## Error Handling

Unknown, deleted, malformed, or cross-shop product IDs must continue to return
validation errors. Only duplicate IDs for valid products become accepted.

## Verification

Add one controller regression test that submits the same valid product ID twice
and expects a successful shipping estimate. Run the existing shipping-estimate
controller tests to verify cross-shop and invalid-product protections remain
intact.

## Scope

No frontend deduplication, order-flow refactor, shipping model change, or new
dependency is needed.
