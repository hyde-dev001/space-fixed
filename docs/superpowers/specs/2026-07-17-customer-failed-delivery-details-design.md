# Customer Failed Delivery Details

## Goal

Show customers why a delivery attempt failed and its proof photo in shipment tracking, while clearly flagging the affected order in My Purchases.

## Data flow

- Load the latest failed `DeliveryAttempt` for each customer-visible shipment leg.
- Expose only its reason code, proof file path, and attempt time through `CustomerTrackingService`.
- Include the latest failed-attempt summary in the existing My Purchases logistics shipment payload.
- Keep rider notes and other internal metadata private.

## UI

- Shipment Tracking shows an amber **Delivery Attempt Failed** panel with the human-readable reason, attempt time, and proof image.
- My Purchases shows an amber **Failed delivery attempt** warning on the matching order with a link to shipment tracking.
- The warning applies to every failed delivery reason, not only recipient unavailable.

## Testing

- Backend tests verify customer payloads expose the safe reason/photo/time fields but not internal notes.
- Frontend tests verify the tracking details and My Purchases warning render for a failed attempt.
