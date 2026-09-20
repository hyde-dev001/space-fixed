# SoleSpace Platform Fee and Balance Design

## Outcome

Add a tenant-scoped, append-only Platform Fee domain for marketplace retail and
repair transactions. Platform fees are merchant liabilities, remain separate
from customer totals and Product VAT, and never apply to POS transactions.

## Existing boundaries reused

- `Order` and `RepairRequest` remain the marketplace transaction records.
- `RetailPosPaymentService` and `RepairPosPaymentService` remain the POS
  payment paths.
- `FinanceShopContext` remains the employee tenant resolver.
- Existing notification, activity/audit, PayMongo webhook, and Inertia finance
  patterns are extended instead of duplicated.
- `ShopOwner::registration_type` is the authoritative individual/company
  classification.

## Domain model

The new platform-fee tables are append-only financial history:

- `platform_fee_settings`: platform defaults and individual/business defaults.
- `shop_platform_fee_settings`: approved shop-specific overrides.
- `platform_fee_charges`: immutable charge snapshots for completed marketplace
  sales.
- `platform_fee_adjustments`: signed reversal/correction entries.
- `platform_fee_payments`: SoleSpace PayMongo payment lifecycle and snapshots.
- `platform_fee_payment_allocations`: immutable payment-to-charge/credit
  allocation records.
- `platform_credit_applications`: immutable refund-credit creation and future
  charge offset records.

All monetary columns use `decimal(14, 2)`. The service layer performs money
arithmetic in integer cents and persists the resulting decimal snapshots.
Historical rates and bases are never recalculated from current settings.

## Eligibility and trigger points

Retail orders and repair requests carry an explicit `origin_channel` marker:
`marketplace` or `pos`. Existing rows fall back to the current POS markers
(`PosTransaction`/invoice metadata and `pricing_breakdown.mode`).

An observer finalizes exactly one charge when an eligible order or repair moves
into its completed state. The observer is update-only, so POS records created
already completed cannot create a charge; the explicit POS marker is the final
guard. Repair warranty/no-charge records are excluded.

Retail fee base is the completed marketplace item subtotal after seller-funded
discounts, excluding shipping. Repair fee base is the completed repair service
amount, excluding shop-owned delivery fees. Customer totals and Product VAT are
not modified.

## Balance and restrictions

`PlatformBalanceService` computes, under a shop row lock when mutating:

```text
outstanding charges + debit adjustments - paid allocations - credit applications
net payable = max(outstanding - available credits, 0)
```

Credits are applied only to eligible marketplace charges; POS transactions do
not create charges, consume credits, or affect reliability inputs.

The effective limit resolves as platform/shop-type defaults followed by an
approved shop override. Warning, critical, and limit crossings are evaluated
from the computed utilization. Enforcement is represented by a platform
restriction state, not the existing suspension state. It blocks new
marketplace retail/repair entry points while leaving POS, finance, approvals,
and platform payment recovery available.

## Payment and approval phases

Phase 2 adds full-balance-only payment requests for business shops, owner
approval, stale snapshot detection, SoleSpace PayMongo checkout creation, and
verified webhook allocation. Individual owners use a direct full-balance flow.
No frontend amount is trusted.

## Reliability and administration phase

Phase 3 adds a dedicated 0-100 daily score service/history, configurable
individual/business recommendation tiers, admin-only limit approval, admin
views/settings, terms disclosure, threshold notifications, and audit detail.
No automatic limit increase or decrease is performed.

## Acceptance contract

- Marketplace online and COD retail/repair completions create one charge.
- Retail and repair POS, regardless of tender, create no charge and leave the
  platform balance, credits, restrictions, and marketplace volume unchanged.
- Product VAT and customer-visible totals remain unchanged.
- Balances are tenant-isolated, server-calculated, and append-only.
- A completed sale that crosses a limit remains valid; only later marketplace
  entry is blocked.
- A financial balance reduction restores marketplace eligibility without
  touching unrelated suspension state.
