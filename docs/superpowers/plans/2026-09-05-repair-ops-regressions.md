# Repair operations regression fixes

## Acceptance criteria

- Shop Owner can approve or reject a finance-approved repair package price change.
- Shipment numbers increment per shop owner; existing internal IDs remain unchanged.
- Repairer dashboard shows an actual or clearly estimated turnaround.
- Accepted repairs remain accepted after POS settlement and can proceed directly to physical receipt.
- Repair booking phone input/API accepts exactly 11 digits.

## Plan

1. Add focused regression coverage for owner approval controls, shipment numbering, dashboard turnaround, POS settlement state, and phone validation.
2. Implement the smallest server/client fixes at the canonical state and presentation boundaries.
3. Run focused tests, diff hygiene, frontend build, and sequential standards/security/simplification checks.

## Constraints

- Work only in feat/rider-gps-tracking.
- Preserve retail-return inspection, tenant scoping, repair identity, payment integrity, and existing internal shipment IDs.
- Preserve the existing monochrome system styling.
