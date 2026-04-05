# VAT-Inclusive Repair Pricing (Phase 1) Design

Date: 2026-04-05
Status: Approved
Scope: Repairs first (phased rollout)

## 1. Context

Current repair pricing behavior adds 12% VAT on top of displayed prices in multiple repair flows. This creates a risk of double charging when displayed prices are already intended as customer-facing final prices.

This design standardizes repair pricing to VAT-inclusive logic for new transactions only, while preserving legacy historical behavior.

## 2. Final Decisions

1. Pricing policy: VAT-inclusive for all customer-facing prices (long-term), with repairs as phase 1 rollout.
2. Historical handling: New transactions only use the new logic; existing records remain unchanged.
3. Deposit policy: For deposit_50, split the displayed final inclusive total into 50/50 phases.
4. VAT storage: Store extracted VAT from inclusive totals using VAT = total * 12 / 112.
5. Rollout strategy: Phased rollout (repairs first, then products/services).
6. UI requirement: Job Order Repair page must show a clear price breakdown, not only a single total.

## 3. Recommended Approach

Hybrid marker plus central calculator.

- Introduce a single shared calculator for VAT-inclusive extraction and phase due calculations.
- Mark new records with explicit tax mode metadata (vat_inclusive) using existing metadata/payload structures.
- Keep legacy record interpretation unchanged when marker is absent.

Why this approach:
- Low risk for production and accounting continuity.
- Deterministic interpretation for new records.
- Avoids forced historical migrations.
- Supports future migration to schema-first design if needed.

## 4. Architecture

### 4.1 Write path (new repair transactions)

- Input amount is treated as VAT-inclusive customer-facing amount.
- Determine due amount per payment policy:
  - full_upfront: 100% of final displayed amount
  - deposit_50: 50% now, 50% later
- Extract VAT from each due phase:
  - vat = round(due * 12 / 112, 2)
  - net = round(due - vat, 2)
- Persist:
  - due total (inclusive)
  - extracted VAT
  - extracted net/subtotal equivalent
  - tax mode marker for inclusive interpretation

### 4.2 Read path

- If tax mode marker indicates inclusive mode:
  - treat stored total as already VAT-inclusive
  - show VAT as informational breakdown only
  - do not add VAT again to grand total
- If marker is absent:
  - keep legacy behavior for compatibility

## 5. Data Flow (Repairs Phase 1)

### 5.1 In-scope repair touchpoints

1. Repair listing/detail payload consumers:
   - ERP repairer job orders view
   - Shop owner repair job orders view
   - User-side repair status/order pages
2. Repair payment activation and retry session payloads.
3. Repair POS checkout, due computation, and receipt generation.
4. Repair summary totals shown in modal/detail pages.

### 5.2 UI display rules

1. Subtotal, VAT breakdown, and Grand Total must be displayed consistently.
2. VAT row is always a breakdown line for inclusive records (not additive).
3. Grand Total in inclusive mode equals the displayed final charge amount.
4. Job Order Repair page must visibly show breakdown lines:
   - Package/Base Price
   - Add-ons Subtotal
   - Subtotal (Before VAT extraction display)
   - VAT (12%)
   - Grand Total

## 6. Error Handling and Edge Cases

1. Legacy guard:
- Do not reinterpret historical records unless explicitly migrated.

2. Rounding consistency:
- Use backend as source of truth for 2-decimal rounding.
- Compute in fixed order: due -> vat extraction -> net.

3. Deposit correctness:
- Split inclusive amount first, then extract VAT per phase.

4. Validation:
- Reject checkout when paid amount does not match expected due total (after rounding).
- Clamp malformed negatives where applicable.

5. Reporting safety:
- Ensure receipts/reports do not add VAT again for inclusive-mode records.

## 7. Testing and Validation Plan

### 7.1 Backend tests

1. VAT extraction formula tests: vat = total * 12 / 112.
2. full_upfront and deposit_50 inclusive due tests.
3. Legacy compatibility tests (marker absent).
4. New record tests (marker present).
5. Payment amount strict-match tests.

### 7.2 Frontend tests

Verify no double-add VAT in these repair pages:
- resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
- resources/js/Pages/ERP/repairer/POS.tsx
- resources/js/Pages/UserSide/Repairs/RepairProcess.tsx
- resources/js/Pages/UserSide/Repairs/myRepairs.tsx

Verify breakdown rendering includes subtotal, VAT, and grand total, especially in Job Order Repair details.

### 7.3 Rollout checks

1. Stage compare sample repair orders before/after change.
2. Monitor payment mismatch errors post-release.
3. Monitor support tickets tied to repair totals and VAT lines.

## 8. Out of Scope (Phase 1)

1. Historical data backfill of paid/closed records.
2. Global products/services refactor in same release.
3. Full schema migration to dedicated tax mode column (can be phase 2).

## 9. Rollout Order

1. Repair APIs and services (calculator + marker + payload consistency).
2. Repair POS and payment flow integration.
3. Repair UI breakdown consistency updates.
4. Staging validation and targeted regression tests.
5. Production rollout with monitoring.

## 10. Success Criteria

1. No new repair transaction computes +12% on top of already displayed final price.
2. VAT line remains visible as breakdown in repair pages.
3. Job Order Repair page shows full price breakdown clearly.
4. Legacy records continue to render without forced migration.
5. Deposit and balance totals remain internally consistent and test-verified.
