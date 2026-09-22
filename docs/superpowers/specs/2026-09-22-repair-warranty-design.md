# SoleSpace Toggleable Repair Warranty Design

## Goal

Allow each repair-capable shop to enable or disable warranties and set a duration while preserving the existing warranty-claim workflow. A warranty starts at customer handover (`picked_up_at`), and its issued dates and original configuration remain unchanged after later settings edits.

## Existing architecture

- `shop_owners.warranty_enabled` and `shop_owners.repair_warranty_days` already store the shop's legacy warranty policy.
- `RepairWarrantyClaim` and `RepairWarrantyService` already implement customer/POS filing, repairer review, logistics, notifications, and the one-approved-claim rule.
- `RepairRequestController::confirmPickup` is the registered-customer handover boundary. `RepairPosController::updateManualQueueStatus` is the manual-POS handover boundary, and `RepairDeliveryService::activateReturnHandoff` covers no-account walk-in release.
- `GET /api/customer/repairs` and `GET /api/customer/repairs/{id}` provide customer repair data. `myRepairs.tsx` already owns the customer warranty-claim modal.
- Shop settings use `ShopSettingsController` plus `shopSetting.tsx`; no additional settings table is needed.

## Data model

Extend the existing shop-owner setting with `repair_warranty_duration_unit` (`days`, `weeks`, or `months`), defaulting to `days`. Keep the existing `warranty_enabled` and `repair_warranty_days` columns for compatibility; the UI presents them as Repair Warranty Enabled and Warranty Duration.

Add immutable snapshot fields to `repair_requests`:

- `repair_warranty_issued` boolean, default `false`;
- `repair_warranty_started_at` timestamp;
- `repair_warranty_expires_at` timestamp;
- `repair_warranty_duration` unsigned small integer;
- `repair_warranty_duration_unit` string.

The snapshot is the source of truth for a completed repair. No customer claim may be authorized from the current shop settings alone. Existing claim snapshot fields remain unchanged.

## Issuance and eligibility

`RepairWarrantyService` becomes the single source of warranty truth:

- `issueAtHandover(RepairRequest $repair)` is idempotent, runs only for a valid non-warranty repair at handover, reads the current shop settings, converts the configured unit with calendar-safe date arithmetic, and stores the original duration/unit and start/expiry timestamps.
- The service exposes a warranty state used by API serialization: issued, active, expired, start, expiry, duration, unit, and `can_claim`.
- `hasWarranty`, active-state, customer ownership, final-state, expiry, cancelled-state, existing claim status, and existing refund/review restrictions remain server-side rules.
- The service is called inside the existing registered-customer, manual-POS, and no-account walk-in handover transitions. Repeated requests cannot change an existing snapshot.
- Disabling the setting prevents issuance for later handovers only. It never clears or recalculates an issued snapshot.

For repairs created before snapshot support, a separate one-time data migration backfills only clearly handed-over legacy repairs (`picked_up_at` present) whose existing legacy setting is enabled, using that setting's duration and the handover timestamp. New records never receive an implicit warranty without an issuance snapshot.

## Shop settings UI

Add a Repair Warranty panel to the existing repair settings area, shown only for repair-capable shops:

- a consistent toggle;
- duration number input and unit select enabled only when the toggle is on;
- helper text explaining that existing warranties remain valid after disabling;
- inline validation/error and the existing black Save action.

The controller validates an enabled duration as positive, uses a supported unit, and caps it at 365 days, 52 weeks, or 12 months respectively. Disabled settings preserve the stored duration and unit.

## Customer visibility

The customer repair payload includes a compact warranty object only when a snapshot exists. `myRepairs.tsx` renders:

- active warranty: start/expiry, remaining days, and `Claim Warranty` only when `can_claim` is true;
- expired warranty: expiry date and a non-actionable expired state;
- no snapshot: no warranty card and no warranty action.

Existing claim-status loading and modal behavior remain in place. Frontend checks are presentation only; the claim endpoint reuses the centralized service authorization.

## Error handling and compatibility

- Invalid settings return the existing Inertia validation errors without changing saved values.
- Unauthorized, warranty-less, expired, cancelled, non-handover, or already-used claims return the existing JSON validation/authorization responses.
- Warranty repair jobs remain ineligible for another warranty claim.
- Existing repairer/POS approval, logistics, notification, refund, and review behavior is not redesigned.

## Verification

Add or update feature tests for settings validation/payload, handover issuance, idempotence, disabled issuance, immutable snapshots, warranty-less and expired claim rejection, ownership, and customer payload state. Add a focused frontend contract/component test for settings controls and active/expired/hidden customer presentation. Run the narrow Laravel warranty tests, focused frontend tests, `git diff --check`, and the production frontend build.
