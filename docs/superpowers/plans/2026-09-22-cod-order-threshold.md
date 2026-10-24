# Configurable COD order threshold

## Acceptance criteria

- Retail-capable shops can enable/disable COD and set a positive merchandise threshold in existing Shop Settings.
- COD is available only for Shop-Owned Logistics when the server-calculated discounted merchandise subtotal is at or below the threshold.
- Individual and business retail shops use the same rule; repair-only, POS, third-party delivery, disabled COD, and over-threshold requests cannot use COD.
- Checkout recalculates eligibility when pricing changes and the create-order endpoint rejects stale or forged COD requests.
- Existing COD collection, remittance, accounting, and existing orders remain unchanged.

## File-level plan

1. Add `cod_enabled` and `cod_order_threshold` to `shop_owners`, model casts/fillable, and the existing Shop Settings controller/page.
2. Add a small `CodEligibilityService` and use it from shipping/preview/create-order paths.
3. Make retail checkout pricing use the current product price before applying existing sale/voucher rules.
4. Add focused Laravel and frontend contract tests, update the obsolete individual-retail rejection test, then run targeted and repository checks.
