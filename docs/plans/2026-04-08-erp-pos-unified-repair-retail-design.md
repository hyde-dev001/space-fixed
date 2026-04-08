# ERP POS Unified Repair and Retail Design

Date: 2026-04-08
Status: Approved for planning
Owners: ERP Repair, ERP Retail, Shop Operations

## 1. Problem Statement

The current ERP POS implementation is repair-focused. Walk-in retail shoe sales are not supported in the ERP cashier experience, causing operational gaps for shops that handle retail-only and mixed retail plus repair workflows.

Current behavior summary:
- Repair POS exists and is integrated with repair ledger and refund workflows.
- Retail checkout logic exists in customer-facing flows but is not exposed as ERP walk-in cashier POS.
- ERP POS UI does not yet enforce business-type mode visibility for retail-only and repair-only shops.

## 2. Goals

1. Support walk-in retail sales inside ERP POS using existing orders and order_items tables.
2. Keep existing repair POS behavior unchanged.
3. Make POS role plus business-type aware:
- staff POS shows retail UI only
- repairer POS shows repair UI only
- both business type enables both capabilities at shop level, but each role still sees only its assigned POS page
4. Support payment methods for retail POS v1:
- cash
- gcash with reference
- card with reference
5. Mark retail walk-in transactions as paid and completed immediately.
6. Include checkout, receipt, and history for retail POS v1.

## 3. Non-Goals

1. No retail POS refund workflow in v1.
2. No rewrite of existing repair refund and ledger logic.
3. No migration to new retail POS tables.

## 4. Approved Approach

Role-separated POS pages with separated backend workflows.

Selected approach:
- Keep repair POS page as repair-only for repairer users.
- Add a dedicated staff retail POS page for retail walk-in sales.
- Repair POS continues using existing repair POS endpoints and rules.
- Staff retail POS uses dedicated retail POS endpoints but writes to existing orders and order_items data model.

Why selected:
- Minimizes regression risk on repair flow.
- Reuses production retail stock and order model.
- Enforces clean role boundaries and prevents mixed-role UI confusion.

## 5. Role and Business Type Visibility Rules

Source of truth:
- auth user shop owner business_type from Inertia props.

Normalized values:
- retail
- repair
- both

UI visibility:
1. staff route and page:
- show retail POS UI only
- never render repair-specific UI blocks
2. repairer route and page:
- show repair POS UI only
- never render retail-specific UI blocks
3. both business type:
- organization supports both flows
- user still sees only the POS page allowed by role and route

Safety behavior:
- Disallowed role or business-type route access returns forbidden and redirects to allowed module.
- Backend endpoint authorization enforces the same role plus business-type constraints.

## 6. Frontend Design

Primary files:
- resources/js/Pages/ERP/repairer/POS.tsx
- resources/js/Pages/ERP/STAFF/RetailPOS.tsx

Planned updates:
1. Keep repairer POS page repair-only.
2. Build a dedicated staff retail POS page.
3. Add retail catalog panel for products and variants in staff page only.
4. Reuse current order, payment, and receipt layout pattern for retail page.
5. Add retail history modal filtered to retail POS orders in staff page.
6. Keep repair history and refund queues only in repairer page.

Sidebar visibility alignment:
- resources/js/layout/AppSidebar_ERP.tsx will expose separate POS items by role, permission, and business type.

## 7. Backend and API Design

### 7.1 Route and Middleware

Web route:
- Keep repairer POS route for repairer users only.
- Add staff retail POS route for staff users only.
- Apply business type middleware per route:
  - repairer POS: repair or both
  - staff retail POS: retail or both

API routes:
- Existing repair POS endpoints remain under repair POS namespace.
- Add retail POS namespace endpoints:
  - GET /api/retail-pos/products
  - POST /api/retail-pos/checkout
  - GET /api/retail-pos/history
  - GET /api/retail-pos/orders/{order}/receipt

Role and business-type guards:
- Repair POS endpoints allow repairer flow on repair or both shops.
- Retail POS endpoints allow staff retail flow on retail or both shops.
- Return 403 with explicit error code for disallowed mode access.

### 7.2 Retail Checkout Persistence

Retail checkout writes to:
- orders
- order_items

Expected persisted state:
- payment_status = paid
- status = completed
- shipping_fee = 0
- payment_method = cash or gcash or card
- customer fields from walk-in form

Order identification for retail POS history:
- Use POS retail prefix in order_number, for example RPOS-YYYYMMDD-XXXX.

### 7.3 Stock Handling

Retail POS stock deduction must reuse existing retail-safe deduction logic:
- variant-aware deduction
- linked inventory support where applicable
- transaction-wrapped mutation

## 8. Validation and Error Handling

Retail checkout validation:
1. At least one line item.
2. Valid product and variant scope.
3. Positive quantity and amount.
4. Stock availability before commit.
5. Payment method in cash, gcash, card.
6. Provider reference required for gcash and card.
7. Customer name required for walk-in.

HTTP response patterns:
- 422 for payload and validation errors.
- 403 for business-type access mismatch.
- 409 for idempotency or conflict conditions.
- 500 for unexpected server errors with structured logs.

## 9. Receipt and History

Receipt:
- Retail checkout returns receipt payload for thermal modal rendering.
- Receipt format remains consistent with POS UI style while showing retail context.

History:
- Retail POS history endpoint queries orders by:
  - current shop scope
  - POS retail order number prefix
- Search fields include receipt or order number, customer, payment method, and date.

## 10. Testing Strategy

### 10.1 Feature Tests

1. retail-only user:
- retail endpoints allowed
- repair endpoints forbidden
2. repair-only user:
- repair endpoints allowed
- retail endpoints forbidden
3. both user:
- both endpoint groups allowed
4. retail checkout:
- creates orders and order_items
- marks paid and completed
- deducts stock correctly

### 10.2 Frontend Tests

1. staff retail POS page renders retail-only UI.
2. repairer POS page renders repair-only UI.
3. repairer page never renders retail sections.
4. staff retail page never renders repair sections.
5. route guards block disallowed role plus business-type combinations.

### 10.3 Regression Tests

1. Existing repair POS checkout remains functional.
2. Existing repair history and refund queue behavior remains unchanged.

## 11. Rollout Plan

Phase 1:
- Keep repairer POS repair-only and remove any retail rendering path there.
- Implement staff retail POS page and route.
- Implement retail POS product list and checkout endpoint.
- Implement receipt rendering for staff retail POS.

Phase 2:
- Implement retail history endpoint and UI modal.
- Add automated tests for business-type matrix and checkout flows.

Phase 3:
- Production hardening and telemetry review.
- Optional future scope for retail POS refund workflow.

## 12. Open Operational Notes

1. Business-type normalization should use existing shared logic patterns to avoid divergent checks.
2. Retail POS history filter must remain shop-scoped to prevent cross-shop leakage.
3. If payment references need stricter format rules later, enforce via request validation extension in a separate change.
