# Repair Promo Phase 1 — Business Rules Specification

## Scope
This document defines the **Phase 1 business rules** for repair promos to prevent double-charging when promos include repair services.

---

## 1) Selection Behavior (Authoritative)

### Allowed customer selection modes
1. **Individual Services Only**
2. **Promo Only**
3. **Promo + Add-on Services** (add-ons must be services **not included** in promo)

### Not allowed
- Selecting an included promo service again as an add-on
- Applying multiple promos in one repair request (Phase 1)

---

## 2) Promo Coverage Rules

When a promo is selected:
- Promo included services are **auto-applied**
- Included services are **read-only/locked** in customer UI
- Customer can only pick additional services not covered by promo
- Final price must be computed server-side

When no promo is selected:
- Existing individual service flow applies

---

## 3) Pricing Rules (Server as source of truth)

Final total formula:

`final_total = promo_price + sum(valid_add_on_services_not_included_in_promo)`

### Mandatory pricing controls
- Ignore/override client-calculated totals
- Validate promo/shop ownership and active validity before computing
- Reject overlap between promo-included services and selected add-ons
- Reject duplicate service IDs in payload

---

## 4) Validation Rules

At create/update request time:
1. `promo_id` (if provided) must belong to target shop
2. Promo must be `active`
3. Current datetime must be within promo validity window
4. Included service IDs must come from server promo definition, not client
5. `add_on_service_ids` must have no intersection with promo included service IDs
6. `add_on_service_ids` must be unique
7. Non-promo flow still requires at least one valid service

---

## 5) UI Rules

Customer UI must enforce:
- Mode switch: **Individual** or **Promo**
- If promo selected:
  - Included services shown with badge: `Included`
  - Included services are non-editable
  - Only non-included services are selectable as add-ons
- Price summary must show:
  - Promo price
  - Add-ons subtotal
  - Final total

---

## 6) Edge Cases and Required Outcomes

### A) Expired promo
- **Behavior:** reject promo at submit
- **Message:** `Selected promo has expired. Please choose another promo or continue with individual services.`

### B) Disabled/inactive promo
- **Behavior:** reject promo at submit
- **Message:** `Selected promo is no longer available.`

### C) Duplicate included service selection
- **Behavior:** reject request if add-on list contains a promo-included service
- **Message:** `Some selected add-on services are already included in the promo.`

### D) Duplicate service IDs in payload
- **Behavior:** reject request
- **Message:** `Duplicate services are not allowed.`

### E) Promo edited after customer started form
- **Behavior:** revalidate at submit using latest server promo definition
- **Message:** `Promo details changed. Please review your selection before continuing.`

### F) Custom/manual price override
- **Customer role:** not allowed
- **Staff/owner role:** allowed only with explicit permission + audit trail + reason
- Override applies only to current request (no promo definition mutation)

---

## 7) Authorization Rules for Overrides

Manual override (if enabled in Phase 1 backend):
- Allowed roles: authorized staff/manager/shop owner only
- Required fields: override reason, actor id, timestamp
- Must produce audit log entry

---

## 8) API Contract Expectations (Phase 1)

### Request (conceptual)
- `promo_id` (nullable)
- `add_on_service_ids` (array, optional if promo only)
- `shop_owner_id`
- other existing repair fields

### Response (conceptual)
- `promo` summary (if applied)
- `included_services`
- `add_on_services`
- `pricing_breakdown`:
  - `promo_price`
  - `add_ons_total`
  - `final_total`

---

## 9) Acceptance Criteria

Phase 1 is done only if all are true:
1. A promo-included service cannot be charged again as add-on
2. Included services become read-only in customer UI
3. Backend rejects overlap regardless of frontend behavior
4. Expired/disabled promo cannot be used
5. Final total is always backend-computed
6. Manual override is permission-gated and auditable

---

## 10) Out of Scope (Phase 1)

- Stacking multiple promos
- Promo combinator rules by customer segment
- Auto best-promo optimization
- Cross-shop promo applicability

---

## Decision Summary (for implementation handoff)

- Strategy chosen: **Promo + optional non-overlapping add-ons**
- Included promo services: **automatic and read-only**
- Overlap prevention: **hard backend validation**
- Pricing authority: **backend only**
- Manual override: **restricted + auditable**
