# ERP Unified Retail + Repair POS Design

Date: 2026-04-08
Status: Approved
Owner: ERP POS Team

## 1. Problem Statement

Current ERP POS is focused on repair transactions. The shop also needs retail walk-in checkout for in-store shoe buyers. A single POS experience is required, with behavior controlled by shop business type.

## 2. Goals

1. Support retail walk-in checkout in ERP POS using existing retail order tables.
2. Keep repair POS behavior intact with no functional regressions.
3. Make POS UI and APIs business-type aware:
- retail: show retail POS only
- repair: show repair POS only
- both: show both retail and repair modes
4. Retail v1 scope includes checkout, receipt, and history only.

## 3. Non-Goals (v1)

1. Retail POS refund workflow from POS screen.
2. New retail-specific database tables for orders.
3. Replacing existing online retail checkout flows.

## 4. Key Decisions

1. Data target for retail POS: existing orders and order_items.
2. Retail payment methods in v1: cash, gcash, card.
3. Retail walk-in successful payment state: paid and completed immediately.
4. POS location: same ERP POS page with mode split.
5. Business type controls available modes and visible UI blocks.

## 5. UX and UI Behavior

Primary page:
- resources/js/Pages/ERP/repairer/POS.tsx

Mode behavior:
1. retail business type:
- Retail mode only.
- Hide repair UI blocks (repair order picker, repair queue, repair refund queue, repair-specific history sections).
2. repair business type:
- Repair mode only.
- Hide retail UI blocks.
3. both business type:
- Show mode switch: Repair | Retail.
- Render only the active mode content.

Fallback behavior:
- If URL/query points to a disallowed mode, auto-switch to the allowed mode in UI.
- Backend remains source of truth and rejects disallowed mode access.

## 6. Backend Architecture

### 6.1 Existing Repair POS (No Breaking Changes)

Keep existing endpoints and services unchanged:
- routes/api.php (repair-pos group)
- app/Http/Controllers/Api/RepairPosController.php
- app/Services/RepairPosPaymentService.php
- app/Services/RepairPosRefundService.php

### 6.2 New Retail POS Endpoints (ERP Staff POS)

Add retail POS API group, separate from repair POS flow:
- GET /api/retail-pos/products
- POST /api/retail-pos/checkout
- GET /api/retail-pos/history

Controller proposal:
- app/Http/Controllers/Api/RetailPosController.php

Service proposal:
- app/Services/RetailPosCheckoutService.php
- app/Services/RetailPosHistoryService.php (optional, if history mapping gets large)

### 6.3 Retail Checkout Persistence

Retail POS checkout writes to:
- orders
- order_items

Required persisted characteristics:
1. customer is walk-in data from POS form.
2. shipping_fee is 0.
3. payment_method is one of cash/gcash/card.
4. payment_status is paid.
5. status is completed.

History discoverability:
- Use dedicated retail POS order marker via order_number prefix (example: RPOS-YYYYMMDD-XXXX) to reliably filter retail POS transactions without schema expansion.

### 6.4 Inventory Consistency

Retail POS checkout must reuse existing retail inventory deduction behavior used in online retail checkout:
- variant-aware checks
- linked inventory-aware updates
- transactional update pattern

This avoids stock drift between ERP walk-in and online checkout channels.

## 7. Security and Access Control

### 7.1 Frontend Gating

Use business type from Inertia auth props and normalize to:
- retail
- repair
- both

Do not render disallowed mode UI blocks.

### 7.2 Backend Enforcement

Each POS API mode must enforce business-type authorization server-side:
- repair POS APIs allowed only for repair and both
- retail POS APIs allowed only for retail and both

If disallowed:
- return HTTP 403 with explicit code (example: BUSINESS_TYPE_FORBIDDEN_MODE)

### 7.3 Route and Sidebar Alignment

Align page discoverability and sidebar visibility with business type rules in:
- resources/js/layout/AppSidebar_ERP.tsx
- routes/web.php
- routes/api.php

## 8. Validation Rules

Retail checkout validation:
1. at least one item line.
2. each item must map to a valid product/variant in current shop scope.
3. requested quantity must be available.
4. payment method must be cash, gcash, or card.
5. provider reference required for gcash/card.
6. customer name required.
7. numeric totals validated server-side from submitted lines.
8. idempotency key required for duplicate protection.

Error semantics:
- 422: payload and validation errors (stock, line mismatch, missing reference)
- 403: business-type mode restriction
- 409: idempotency conflict or replay collision where applicable

## 9. Testing Strategy

### 9.1 Feature Tests

1. retail-only actor cannot use repair POS APIs.
2. repair-only actor cannot use retail POS APIs.
3. both actor can use both modes.
4. retail POS checkout creates orders and order_items and marks paid/completed.
5. retail POS checkout updates stock correctly for variant-linked inventory scenarios.

### 9.2 Frontend Tests

1. retail business type shows retail-only mode.
2. repair business type shows repair-only mode.
3. both business type shows mode switch with isolated content per mode.
4. disallowed mode query auto-corrects to allowed mode.

### 9.3 Regression Tests

1. existing repair POS checkout flow unchanged.
2. repair POS refund UI/logic unchanged.
3. repair manual queue behavior unchanged.

## 10. Rollout Plan

Phase 1:
1. Add mode-aware POS UI shell and business-type gating.
2. Implement retail products list and checkout endpoint.
3. Implement retail receipt rendering in existing receipt modal pattern.

Phase 2:
1. Add retail POS history endpoint and UI list.
2. Add full business-type and checkout tests.
3. Verify production-like stock scenarios with linked inventory variants.

## 11. Risks and Mitigations

1. Risk: Stock mismatch between retail POS and online checkout.
- Mitigation: Reuse existing deduction logic and add integration tests.

2. Risk: Hidden UI but unauthorized API still callable.
- Mitigation: strict backend business-type guards with 403 responses.

3. Risk: Repair POS regressions while adding retail mode.
- Mitigation: isolate retail code paths and preserve repair services/endpoints unchanged.

## 12. Acceptance Criteria

1. Retail shops can complete walk-in retail checkout from ERP POS.
2. Retail business type never sees repair POS UI blocks.
3. Repair business type never sees retail POS UI blocks.
4. Both business type sees both retail and repair modes.
5. Retail POS orders are persisted in existing orders/order_items as paid and completed.
6. Repair POS behavior remains stable and unchanged.
