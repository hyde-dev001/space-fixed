# Live Rider Tracking Button Design

## Goal

Add a motor-icon action beside each shipment's existing `Open delivery` action on the ERP Logistics Shipments page. The action is shown only when the current dispatcher live-location response contains a GPS record for that exact shipment. Clicking it opens the existing shipment tracking modal.

## User-visible behavior

- A shipment gets a motor icon only when a live-location record has `shipment_id === shipment.id`.
- A missing response, an empty response, a record for another shipment, or a location with no shipment ID produces no motor icon for that shipment.
- Clicking the icon opens the existing `ShipmentTrackingModal` for the selected shipment ID.
- The existing `Open delivery` action, map layout, modal behavior, labels, and surrounding page styling remain unchanged.
- The icon has an accessible label, tooltip, dialog relationship, keyboard focus styling, and returns focus to its trigger when the modal closes.

## Architecture and data flow

The existing `DispatcherLiveTracking` component already polls `/api/logistics/live-locations` and owns the global live map. It will gain one optional callback:

```ts
onLocationsChange?: (locations: LiveRiderLocation[]) => void;
```

After each successful poll, it will normalize the response to an array, preserve its current state/map/list behavior, and notify the parent with that same array. The callback is optional so other callers are unaffected.

`Shipments` will pass a stable callback to `DispatcherLiveTracking` and retain the latest successful location array. Each shipment row will derive visibility with a strict shipment-ID comparison:

```ts
liveLocations.some((location) => location.shipment_id === shipment.id)
```

This reuses the existing poll, avoids duplicate requests, and uses the existing `LiveRiderLocation` type. No backend or tracking endpoint changes are needed.

## Shipment page changes

`resources/js/Pages/ERP/Logistics/Shipments.tsx` will:

1. Import the existing Lucide motor/bike icon, `ShipmentTrackingModal`, and `LiveRiderLocation`.
2. Add state for the latest live locations and the selected tracking shipment ID.
3. Add a stable location-change callback and pass it to the existing global tracking component.
4. Render the icon only when the page is in the dispatcher shipment view, live tracking is enabled, the user can view shipments, and the exact shipment ID is present in the latest successful location response.
5. Render one existing `ShipmentTrackingModal` at page level and pass it the selected shipment ID.
6. Place the new icon and the unchanged `Open delivery` button in the existing action area without changing the latter's action or styling semantics.

The modal will remain responsible for fetching and rendering the shipment tracking details. It will not be duplicated per row.

## Polling, errors, and empty state

The callback will run only after a successful live-location response. An empty successful response replaces the stored array with an empty array, so all motor icons disappear. A transient polling error follows the existing `DispatcherLiveTracking` error behavior and leaves the last successful location set in place until the next successful response; this avoids introducing new flicker or changing the existing tracking component's state semantics.

When live tracking is disabled, unavailable to the current view, or not permitted, the page explicitly hides all motor icons and does not open the new modal from those actions.

## Accessibility and focus

The motor button will be icon-only but include an accessible `aria-label`, a `title`, `aria-haspopup="dialog"`, a minimum existing touch target, and the page's established focus-ring styles. The clicked button will be saved as the modal return-focus target. Closing the modal clears the selected shipment and restores focus through the existing modal focus handling.

## Non-goals and invariants

This change will not modify:

- GPS polling intervals or location update behavior;
- rider marker movement, route polylines, route colors, or map controls/layout;
- dispatcher stop order, `stop_sequence`, batch-aware routing, restrictions, or optimization;
- rider assignment, delivery statuses, retail return workflows, repair workflows, or customer tracking permissions;
- the existing shipment tracking modal, backend endpoint, authorization, or tracking payload;
- the CARTO basemap configuration or any unrelated ERP feature.

## Verification and acceptance criteria

Tests will cover:

- `DispatcherLiveTracking` forwarding the successful location array to its optional callback and not polling/calling it while disabled;
- a matching live location showing exactly one motor action for the matching shipment;
- a nonmatching or empty location array showing no motor action;
- clicking the motor action opening the existing shipment tracking dialog for the correct shipment;
- existing shipment and tracking tests continuing to pass.

Final verification will include the focused frontend tests, the production frontend build if available, `git diff --check`, and a final diff/status review. Browser verification will be reported separately if the local app can be run.

## Security and authorization

The UI will derive visibility only from the already-authorized live-location response and will not add a new endpoint or trust a client-supplied authorization value. The existing tracking modal request and server-side authorization remain the source of truth.
