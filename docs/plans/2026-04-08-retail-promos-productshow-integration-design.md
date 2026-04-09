# Retail Promos and ProductShow Integration Design

Date: 2026-04-08
Status: Approved
Owner: Shop Owner Retail Flow

## Objective

Connect the Shop Owner Vouchers and Discount page and User ProductShow page using a shared backend promo module, so shop owners can:
- publish sale pricing that makes shoes look on sale,
- create vouchers for shop-wide or specific products,
- expose real voucher claim behavior on ProductShow,
- support both business and individual shop owner account types.

## Confirmed Decisions

1. Account behavior: same capability for both registration types (individual and company).
2. Voucher scope: both shop-wide and product-specific.
3. Discount order: apply sale first, then voucher.
4. ProductShow claim behavior: auto-apply in checkout context.
5. Existing company staff pricing governance remains: shop owner direct update allowed, staff approval workflow unchanged.

## Architecture

Introduce a dedicated Retail Promo module consumed by:
- Shop Owner campaign management UI in resources/js/Pages/ShopOwner/Orders/order management/discount.tsx
- Product page voucher strip in resources/js/Pages/UserSide/Products/ProductShow.tsx
- Checkout summary and application in resources/js/Pages/UserSide/Orders/payment.tsx

Keep existing product price approval flow intact in app/Http/Controllers/Api/ProductController.php and app/Http/Controllers/Api/PriceChangeRequestController.php.

## Data Model

### promo_campaigns

- id
- shop_owner_id
- kind (voucher | sale)
- scope (shop_wide | product_specific)
- name
- code (nullable for sale)
- discount_mode (percentage | fixed)
- value
- min_spend (default 0)
- usage_limit (nullable = unlimited)
- used_count (default 0)
- start_at
- end_at
- status (draft | scheduled | active | expired | disabled)
- stacking_mode (default combinable with sale rule)
- timestamps

Indexes:
- (shop_owner_id, status)
- unique(shop_owner_id, code) where code is not null

### promo_campaign_products

- id
- promo_campaign_id
- product_id
- timestamps

Indexes:
- unique(promo_campaign_id, product_id)
- (product_id)

### voucher_claims

- id
- promo_campaign_id
- user_id
- shop_owner_id
- status (claimed | redeemed | expired | cancelled)
- claimed_at
- redeemed_at (nullable)
- timestamps

Indexes:
- unique(promo_campaign_id, user_id)
- (user_id, status)
- (shop_owner_id)

## API Contracts

## Shop Owner APIs

Prefix: /api/shop-owner/promos

- GET /api/shop-owner/promos
- POST /api/shop-owner/promos
- PUT /api/shop-owner/promos/{id}
- PATCH /api/shop-owner/promos/{id}/status
- DELETE /api/shop-owner/promos/{id}
- GET /api/shop-owner/promos/products

Rules:
- product_specific campaigns require at least one owned product.
- code required for vouchers and unique per shop owner.
- schedule validation ensures start_at < end_at.

## Customer APIs

- POST /api/products/{productId}/vouchers/{campaignId}/claim
- GET /api/checkout/vouchers/eligible (or equivalent checkout payload embed)

Claim validation:
- authenticated user guard required,
- campaign active and within schedule,
- product-shop ownership consistency,
- product target match for product-specific vouchers,
- no duplicate claim.

## ProductShow and Checkout Data Flow

1. LandingPageController productShow response adds promo context:
- active sale context,
- applicable vouchers,
- claimed flags for authenticated customer.

2. ProductShow:
- remove staticVoucherCampaigns usage,
- render campaigns from backend,
- claim button calls claim endpoint,
- preserve login-required behavior.

3. Checkout:
- compute sale-adjusted item totals first,
- evaluate claimed eligible vouchers next,
- auto-apply eligible voucher in current checkout context,
- show applied campaign details in summary.

## Validation and Error Handling

Create and update validation:
- kind, scope, discount_mode strict enums,
- value > 0,
- percentage <= 100,
- min_spend >= 0,
- schedule valid,
- product ownership enforced for product-specific scope.

Runtime safety:
- DB transactions for claim and redemption counters,
- unique constraints prevent duplicate claim races,
- graceful UI fallback when promo APIs fail.

## Testing Strategy

Backend feature tests:
- promo CRUD authorization and ownership scope,
- claim duplicate prevention,
- schedule and status transitions,
- sale-then-voucher calculation correctness,
- usage-limit enforcement.

Frontend tests:
- discount.tsx CRUD integration and validation surfacing,
- ProductShow voucher rendering and claim states,
- checkout applied-voucher summary and totals.

Regression checks:
- company staff price-change approval workflow unchanged,
- individual and company shop owner access remains valid.

## Rollout Plan

1. Ship migrations and models behind safe defaults.
2. Add APIs and wire shop-owner page first.
3. Replace ProductShow static vouchers with dynamic data.
4. Wire checkout auto-apply behavior.
5. Run regression tests on pricing approval and product flows.

## Non-Goals

- No unified advanced campaign-rule engine in this phase.
- No changes to existing repair promo and repair POS discount logic.
