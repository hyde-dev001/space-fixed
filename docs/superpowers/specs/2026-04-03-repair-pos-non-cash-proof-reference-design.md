# Repair POS Non-Cash Proof Reference Design

## Summary
Implement a cashier-friendly in-store POS payment flow where GCash/Card can proceed without cash input, while requiring proof reference for non-cash tenders. Apply the same behavior to both Shop Owner and ERP Repairer POS pages and enforce policy in backend validation.

## Goals
- Allow POS checkout to proceed for GCash/Card without requiring cash received.
- Require proof reference for non-cash methods.
- Keep cash validations strict (phone required, cash amount checks).
- Display proof reference in both printed receipt output and internal records/history.
- Enforce rules in frontend and backend to prevent API bypass.

## Non-Goals
- No PayMongo hosted checkout redirect from POS.
- No provider-specific regex for references (only non-empty required).
- No schema migration or backfill for historical transactions.

## Scope
- In scope pages:
  - `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
  - `resources/js/Pages/ERP/repairer/POS.tsx`
- In scope backend endpoint:
  - `POST /api/repair-pos/checkout` in `app/Http/Controllers/Api/RepairPosController.php`
- In scope tests:
  - `tests/Feature/RepairPosPaymentFlowTest.php`

## Functional Requirements

### 1) Method-specific cashier behavior
- Cash:
  - Phone number remains required and must be 11 digits.
  - Cash received remains required.
  - Insufficient cash blocks Pay.
  - Non-cash proof reference is not required and should be cleared.
- GCash/Card:
  - Phone is optional.
  - Cash received is not required.
  - Proof reference is required (any non-empty string).

### 2) Field reset behavior
- When payment method switches from GCash/Card to Cash, proof reference is auto-cleared.

### 3) Receipt output behavior
- For non-cash with missing phone, receipt shows `Phone: N/A`.
- For non-cash, include `Reference: <provider_reference>` in printable receipt text.
- Internal receipt snapshot/history stores and surfaces proof reference.

### 4) Backend enforcement
- Checkout validation rejects non-cash payment lines if `provider_reference` is empty.
- Cash payment lines continue to allow nullable `provider_reference`.
- Backend returns clear 422 validation errors to UI.

## Data Flow
1. Cashier selects payment method.
2. UI toggles required fields:
   - Cash -> phone + cash input required.
   - GCash/Card -> proof reference required.
3. UI builds checkout payload with one `payment_lines` record.
4. Backend validates method-specific rules.
5. Service stores `provider_reference` in `pos_payment_lines`.
6. Receipt payload is issued and rendered in UI/print.

## Error Handling
- Frontend inline validation prevents pay button from enabling when required fields are incomplete.
- Backend 422 errors are shown as user-friendly messages.
- Validation message target: `payment_lines.0.provider_reference` equivalent rule failure.

## Testing Strategy
- Extend feature tests to cover:
  - Non-cash checkout fails without `provider_reference`.
  - Non-cash checkout succeeds with `provider_reference`.
  - Cash checkout still succeeds with nullable `provider_reference`.
- Manual UI validation on both POS pages:
  - Non-cash pay enabled without phone when proof exists.
  - Switching to cash clears proof reference.
  - Receipt prints `Phone: N/A` and non-cash reference line.

## Risks and Mitigations
- Risk: Divergent behavior between Shop Owner and ERP POS pages.
  - Mitigation: Apply symmetrical changes to both files and validate both manually.
- Risk: Existing non-cash historical records without reference.
  - Mitigation: New validation only affects new checkout requests.

## Acceptance Criteria
- Cashier can complete POS payment with GCash/Card without entering cash received.
- GCash/Card cannot proceed without proof reference.
- Cash flow remains unchanged and strict.
- Non-cash reference appears in printed and internal receipt representations.
- Backend blocks non-cash requests with missing reference even if UI is bypassed.

## Final Verification Notes

### Automated verification
- Ran `pnpm vitest run resources/js/Pages/Repairs/__tests__/posPaymentValidation.test.ts` and all tests passed.
- Ran `php artisan test tests/Feature/RepairPosPaymentFlowTest.php` and all tests passed.

### Manual QA checklist
1. Cash selected, empty phone -> Pay disabled. Verified by helper-gated `canPay`.
2. Cash selected, valid phone and enough cash -> Pay enabled. Verified by helper-gated `canPay`.
3. GCash selected, empty proof reference -> Pay disabled. Verified by helper-gated `canPay`.
4. GCash selected, proof reference filled, phone empty -> Pay enabled. Verified by helper-gated `canPay`.
5. Switch GCash -> Cash -> proof reference auto-clears. Verified via effect hook in both POS pages.
6. Complete non-cash checkout -> receipt shows Phone: N/A when no phone. Verified by receipt phone fallback helper usage.
7. Complete non-cash checkout -> receipt shows Reference: <value>. Verified by snapshot and print rendering updates.
