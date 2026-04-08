# Retail Promos and ProductShow Integration Design

Date: 2026-04-08
Status: Approved
Owner: Shop Owner Retail Flow

## 1. Goal

Connect the Shop Owner Vouchers and Discount page and ProductShow page to one backend promo system so that:
- Shop owners can create sale campaigns and voucher campaigns.
- Campaigns can target either all shop products or selected products.
- ProductShow shows real active vouchers instead of static mock data.
- Customer pricing follows sale first, then voucher.
- Checkout auto-applies eligible claimed voucher.
- Existing staff price approval governance remains unchanged.

## 2. Confirmed Decisions

1. Account behavior:
- Both individual and company shop owners can manage promos directly.

2. Voucher scope:
- Both shop-wide and product-specific vouchers are supported.

3. Discount order:
- Apply sale price first.
- Apply voucher second.

4. Claim behavior:
- Claim in ProductShow should support immediate checkout auto-apply behavior.

5. Company governance:
- Keep existing rule: shop owner direct update allowed, staff still follows existing price approval workflow.

## 3. Architecture

Implement a dedicated retail promo module and connect both pages to it.

### 3.1 Core tables

1. promo_campaigns
- id
- shop_owner_id
- kind (voucher or sale)
- scope (shop_wide or product_specific)
- name
- code (required for voucher, nullable for sale)
- discount_mode (percentage or fixed)
- value
- min_spend
- usage_limit
- used_count
- start_at
- end_at
- status (draft, scheduled, active, expired, disabled)
- created_at, updated_at

2. promo_campaign_products
- id
- promo_campaign_id
- product_id
- created_at, updated_at

3. voucher_claims
- id
- promo_campaign_id
- user_id
- shop_owner_id
- claimed_at
- redeemed_at
- status (claimed, redeemed, expired, cancelled)
- created_at, updated_at

### 3.2 Relationships

- ShopOwner hasMany PromoCampaign.
- PromoCampaign belongsTo ShopOwner.
- PromoCampaign belongsToMany Product through promo_campaign_products.
- PromoCampaign hasMany VoucherClaim.
- VoucherClaim belongsTo PromoCampaign, belongsTo User.

## 4. API Design

## 4.1 Shop Owner promo management APIs

Prefix: /api/shop-owner/promos

- GET /api/shop-owner/promos
  - List campaigns with filters (kind, status, scope, search).
- GET /api/shop-owner/promos/products
  - Product options for targeting.
- POST /api/shop-owner/promos
  - Create campaign.
- PUT /api/shop-owner/promos/{id}
  - Update campaign.
- PATCH /api/shop-owner/promos/{id}/status
  - Change status (enable, disable, archive).
- DELETE /api/shop-owner/promos/{id}
  - Soft delete campaign.

## 4.2 Customer product and claim APIs

- GET /api/products/{productId}/promos
  - Return active promo context for ProductShow:
    - active sale context
    - active voucher cards
    - claim state for authenticated customer
- POST /api/products/{productId}/vouchers/{campaignId}/claim
  - Claim voucher for logged-in customer.

## 4.3 Checkout apply APIs

- POST /api/checkout/promos/resolve (or integrated in existing checkout pricing endpoint)
  - Inputs: cart lines, shop grouping, selected payment flow.
  - Output:
    - sale-adjusted subtotal per line
    - eligible claimed vouchers
    - auto-applied voucher (if any)
    - discount breakdown

## 5. Frontend Integration

## 5.1 Shop Owner page updates

File: resources/js/Pages/ShopOwner/Orders/order management/discount.tsx

- Replace local mock products and campaigns with API fetch.
- Add scope selector:
  - Shop-wide
  - Product-specific
- Add product multiselect when product-specific is chosen.
- Persist create/update/delete/status via APIs.
- Show validation and API error messages.

## 5.2 ProductShow updates

File: resources/js/Pages/UserSide/Products/ProductShow.tsx

- Remove staticVoucherCampaigns dependency.
- Render voucher strip from promo payload.
- Keep login requirement for claim action.
- Prevent duplicate claim attempts in UI and backend.

## 5.3 Product payload updates

File: app/Http/Controllers/UserSide/LandingPageController.php

- Add promo_context to product payload:
  - effective sale data
  - active vouchers
  - claim state for current customer

## 5.4 Checkout updates

File: resources/js/Pages/UserSide/Orders/payment.tsx

- Show applied voucher summary and recalculated totals.
- Auto-apply eligible claimed voucher after sale adjustment.

## 6. Pricing Rules

1. Effective line base = sale-adjusted price.
2. Voucher eligibility checks run on adjusted subtotal.
3. Voucher value is applied after sale.
4. If no eligible claim exists, checkout proceeds without voucher.

## 7. Validation and Error Handling

## 7.1 Campaign validation

- name required
- kind in voucher or sale
- scope in shop_wide or product_specific
- discount_mode in percentage or fixed
- value > 0
- percentage <= 100
- start_at < end_at
- voucher code unique per shop_owner for active and scheduled campaigns
- product_ids required when scope is product_specific

## 7.2 Claim validation

- customer authentication required
- campaign must be active and within schedule
- campaign must belong to product shop owner
- product-specific campaign must target the current product
- one active claim per user per campaign

## 7.3 Concurrency and integrity

- Use DB transactions for claim and usage update.
- Add unique DB index for (promo_campaign_id, user_id) active claim.
- Increment used_count atomically at redemption.

## 8. Account-Type and Governance Compatibility

- Individual and company shop owners can manage promotions.
- Existing staff price approval logic in ProductController remains unchanged.
- Promo management does not bypass existing staff direct-price restrictions.

## 9. Testing Plan

## 9.1 Backend tests

- Promo CRUD authorization by shop owner.
- Product ownership enforcement for product-specific promos.
- Claim duplicate prevention.
- Sale-then-voucher calculation correctness.
- Usage limit and schedule edge cases.

## 9.2 Frontend tests

- discount.tsx loads and saves campaign data via API.
- ProductShow renders live vouchers and claim states.
- Checkout shows auto-applied voucher breakdown.

## 9.3 Regression tests

- Price approval workflow behavior unchanged.
- Existing product page and add-to-cart behavior remains stable if promos fail.

## 10. Rollout Notes

1. Deploy migrations first.
2. Deploy APIs and feature tests.
3. Switch ProductShow from static vouchers to API data.
4. Enable checkout resolve path for auto-apply.
5. Monitor claim and redemption logs.

## 11. Out of Scope for This Iteration

- Category-level vouchers.
- Multi-voucher stacking.
- Cross-shop voucher pooling.
- Admin global promo override.
