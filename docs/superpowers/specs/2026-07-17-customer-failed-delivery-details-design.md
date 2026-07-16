# Customer Failed Delivery Details

## Goal

Show customers why a delivery attempt failed and its proof photo in shipment tracking, while clearly flagging the affected order in My Purchases.

## Data flow

- Load the latest failed `DeliveryAttempt` per leg using `attempted_at DESC, id DESC`.
- `CustomerTrackingService` exposes a nullable `latest_failed_attempt` with `id`, a customer-safe `reason`, ISO-8601 `attempted_at`, and nullable `proof_url`.
- Map reason codes exactly: `recipient_unavailable` → `Recipient unavailable`; `wrong_or_incomplete_address` → `Wrong or incomplete address`; `recipient_refused` → `Recipient refused`; `vehicle_or_delivery_problem` → `Vehicle or delivery problem`; and `other` → `Other delivery issue`. Unknown or legacy codes use `Delivery could not be completed`; rider-entered notes never become the reason.
- `proof_url` targets an authenticated customer route. The route verifies that the signed-in customer owns the shipment and that the attempt belongs to one of its legs before returning the image. Raw storage paths are never returned.
- The current leg is the leg with the highest `sequence`, then highest `id` as a tie-breaker. Only its unresolved failure drives the active warning; failed attempts on other legs remain historical details.
- The existing My Purchases lookup receives only `has_failed_attempt` and the matching shipment ID. It remains scoped to `source_type = order`, the exact order ID, and the latest shipment by `id DESC`; older shipments for the same order cannot drive the warning or link.
- `has_failed_attempt` is true when the current leg has a failed attempt and is not `awaiting_proof_approval` or `delivered`. A successful redelivery clears the My Purchases warning; Shipment Tracking retains the failed attempt as historical detail.
- Keep rider notes, recorder identity, internal resolution data, and raw file paths private.

## UI

- Shipment Tracking shows an amber **Delivery Attempt Failed** panel for an unresolved current-leg failure, with the human-readable reason, attempt time, and proof image. After successful redelivery it remains available as historical detail without an active-warning treatment.
- If proof is missing or was deleted, tracking shows `Attempt photo unavailable` without a broken image.
- My Purchases shows an amber **Failed delivery attempt** warning only on the exact matching order, with a link to `/tracking/shipments/{shipment}`.
- The warning applies to every failed delivery reason, not only recipient unavailable.

## Testing

- Backend tests cover every exact reason-code mapping and the unknown fallback; latest-attempt ordering and tie-breaking; no-attempt and missing-proof cases; failed-then-delivered lifecycle; current-leg selection with other-leg failures kept historical; exact order matching; newest-shipment selection when an order has multiple shipments; and the absence of notes, recorder data, internal resolution details, and raw paths.
- Proof route tests cover the owning customer, unauthenticated access, cross-customer access, and an attempt from a different shipment.
- Frontend tests cover the unresolved warning, reason/time/photo rendering, missing-photo fallback, successful-redelivery historical state, exact My Purchases order warning, and correct tracking link.
