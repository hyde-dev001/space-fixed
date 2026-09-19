# Shop-Owned Logistics Vouchers Design

## Goal

Let a retail shop owner create vouchers that reduce the delivery fee for orders delivered through that shop's own logistics. The shop owner chooses the minimum spend and discount value; examples such as free shipping above a chosen amount or 50% off shipping are not fixed presets.

The customer payment page must show the voucher as effective: the original shipping fee, the shipping-voucher deduction, the remaining shipping fee, and the updated order total must agree with the server calculation.

## Scope decisions

- Reuse the existing `PromoCampaign` and voucher claim/check-out lifecycle.
- Add a `discount_target` to campaigns with `items` as the legacy/default target and `shipping` for shop-owned logistics vouchers.
- Shipping campaigns are vouchers only, shop-wide only, and never attach to a product.
- Allow percentage shipping discounts from 0.01 through 100 and fixed shipping discounts from 0.01 upward. The applied amount is always capped at the actual shipping fee, so free shipping is represented by a discount that brings the fee to zero.
- Use the existing minimum-spend field for any shop-owner-selected threshold, including zero.
- Keep one voucher per order. An explicitly selected shipping voucher takes the voucher slot; existing product-voucher behavior remains unchanged when no shipping voucher is selected.
- A shipping voucher is valid only when the shop can access the logistics module and the selected customer address is inside the shop-owned coverage returned by the existing delivery schedule service.
- Third-party or customer-arranged delivery never receives a shipping-voucher discount.
- The checkout server recalculates all eligibility and amounts during both preview and order creation. Client totals are presentation only.

## Alternatives considered

1. **Extend `PromoCampaign` with a target (chosen).** It preserves claims, usage limits, status scheduling, owner scoping, and current product-voucher compatibility. A migration default keeps existing rows as item vouchers.
2. **Create a separate shipping-voucher table.** This would duplicate campaign scheduling, code uniqueness, claims, redemption, and owner authorization for one new target, increasing migration and regression risk.
3. **Apply the discount only in React.** This would be easy to display but would allow a client to change the shipping amount or bypass the no-own-logistics rule, so it is rejected.

## Shop-owner flow

The Vouchers & Discount page keeps its existing campaign tracker and product-discount path. In the voucher form, the owner chooses the voucher target:

- **Product voucher:** existing behavior, including shop-wide or product-specific scope.
- **Shipping voucher:** available only when the owner has an enabled, eligible Logistics module. The form becomes shop-wide, hides product selection, and explains that the voucher applies only to deliveries fulfilled by the shop.

Shipping vouchers use the existing name, code, discount mode, value, minimum spend, usage limit, start date, and end date fields. The preview card labels the benefit as shipping rather than implying a product discount. If the logistics module is unavailable, the target is disabled with a clear reason and the API also rejects a forged shipping target.

## Customer checkout flow

The existing voucher box on the payment page accepts both targets. Voucher suggestions and the applied-voucher summary expose a `target` label so customers can distinguish “Product” from “Shipping”. The payment request includes the raw shipping estimate plus the selected address/coordinates needed for the server to validate shop-owned coverage.

When a shipping voucher is eligible, the payment summary displays:

1. the original shipping estimate,
2. a separate negative “Shipping voucher” line with the campaign name/code,
3. the discounted shipping amount (zero when free shipping applies), and
4. a total that includes the discounted shipping amount.

When the address is outside coverage, the shop has no enabled logistics module, or the minimum spend is not met, the voucher remains unapplied and the payment page shows the server's safe explanation. Product-voucher totals and third-party shipping presentation remain unchanged.

## Server calculation contract

The existing promo preview response remains backward compatible and gains:

- `raw_shipping_fee`
- `shipping_voucher_discount`
- `discounted_shipping_fee`
- `shipping_voucher_error` when a selected shipping voucher cannot apply
- `target` on available and applied voucher summaries

`CheckoutController` continues to use `PromoPricingService` for sale-then-product-voucher pricing. A focused logistics shipping-voucher service applies the selected shipping voucher after validating the shop module and `DeliveryScheduleService::coverage`. It accepts only an active, non-expired shipping campaign owned by the resolved shop, checks minimum spend against the sale-adjusted item subtotal, and caps the result at the raw shipping fee.

`createOrder` repeats the same calculation inside the existing transaction and persists the final shipping amount to the order. Voucher redemption happens only after the order and applied campaign are valid. Payment retry sessions use the persisted order total rather than trusting a client-provided discounted amount.

## Authorization and safety

- Shop-owner CRUD remains behind the existing authenticated owner, isolation, and retail/both middleware.
- The API checks the authenticated owner's `ShopModuleAccessService` decision before creating or updating a shipping campaign.
- Customer `address_id` is scoped to the authenticated customer; draft coordinates are bounded and validated before coverage lookup.
- No request field can select another shop's campaign, address, product, or logistics settings.
- Invalid shipping targets fail closed with a safe validation response; they do not silently become product vouchers.
- Existing campaign rows default to `items` and existing product voucher/sale behavior remains intact.

## UI and accessibility direction

Use the existing SoleSpace `DESIGN.md` direction: neutral Nike-inspired surfaces, black/white/soft-cloud tokens, restrained borders, an 8px spacing rhythm, readable Helvetica-style typography, and existing icon conventions. Keep the current page's information hierarchy while making the shipping target and logistics eligibility visible before submission.

The shipping target control needs a visible label, helper/error text adjacent to the control, keyboard focus styling, 44px minimum interactive targets, and responsive layouts at 375px, 768px, 1024px, and 1440px. Do not add emoji or decorative-only controls. Payment totals must remain readable in light/dark themes and must not rely on color alone to communicate the discount.

## Acceptance criteria

- A shop owner with enabled own logistics can create an active shipping voucher with any chosen minimum spend and either percentage or fixed value.
- A shop owner without eligible/enabled own logistics cannot create or update a shipping voucher, even by calling the API directly.
- Shipping vouchers are shop-wide and cannot target a product.
- A percentage voucher such as 50% reduces only the actual shipping fee and never makes it negative.
- A fixed voucher larger than the shipping fee produces free shipping, not a negative order total.
- A shipping voucher below its owner-selected minimum spend does not apply.
- A customer outside the shop-owned coverage or using third-party delivery receives no shipping discount.
- Payment preview and order creation return the same final shipping amount for the same valid request.
- The payment page visibly shows the original fee, shipping-voucher deduction, discounted fee, and updated total.
- Existing product vouchers, product discounts, claims, usage limits, and redemption behavior continue to pass.
- Focused Laravel tests, frontend tests, a fresh Vite build, and diff hygiene checks pass.

## Testing outline

- Schema/model compatibility: existing campaigns default to `items`; shipping target accepts only supported values.
- Shop-owner API: create/list/update shipping vouchers for enabled logistics; reject ineligible owners, product scopes, and forged targets.
- Checkout preview: percentage, fixed/free, minimum spend, no logistics module, outside coverage, and customer-address ownership.
- Order creation: final shipping persistence, voucher redemption, no negative totals, and legacy product voucher regression.
- Frontend: shipping target form state/guard and payment summary rendering for original fee, discount, and final fee.
- Run the existing logistics, promo, checkout, and frontend suites without changing unrelated logistics code.
