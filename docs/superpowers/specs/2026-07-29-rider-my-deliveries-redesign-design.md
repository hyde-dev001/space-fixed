# Rider My Deliveries Redesign

**Date:** 2026-07-29
**Status:** Approved direction; implementation pending
**Scope:** Target rider-facing `My Deliveries` experience, with an explicit first implementation slice covering the page redesign and the minimum shared active-work safeguard

## Summary

Redesign the rider `My Deliveries` page as a task-first workspace. The page must answer one question before showing anything else: **What should I do now?**

The default view pins exactly one current work item, shows one upcoming assignment, and moves the rest into compact status-based lists. A work item may be either:

- a batch containing an ordered sequence of deliveries; or
- one standalone delivery.

The UI presents both through the same rider workflow without creating a synthetic one-stop batch.

## Current Experience and UX Analysis

The current page renders open batch cards above a separate shipment list. This produces two competing information structures on one page.

Current behavior creates these problems:

- Every offered, accepted, or in-progress batch receives similar visual weight.
- Every batch independently identifies a “next stop,” so a rider with multiple batches may see several competing next actions.
- Batch cards continuously stack as work is offered, accepted, or started.
- Search and filters belong to the shipment list below the batches, so their scope is unclear.
- Completed shipments appear beneath operational work and lengthen the page.
- Batch, stop, bulk, contact, proof, and status actions compete within the same card.
- `awaiting_proof_approval` is treated as complete by the current progress calculation even though delivery is not final.
- Batches are ordered by delivery date but not clearly prioritized by state, window, urgency, or acceptance time.
- System language such as “Leg” does not match the rider’s mental model.
- Standalone deliveries and batched deliveries use different presentation paths even though the rider performs the same basic workflow.

The result is high cognitive load, weak action hierarchy, and uncertainty about which assignment should be handled next.

## Design Principles

1. Show one current task.
2. Show one primary action for the current state.
3. Keep accepted future work visible but visually subordinate.
4. Process each delivery individually, even when it belongs to a batch.
5. Use the same rider-facing workflow for batched and standalone deliveries.
6. Do not rely on color alone for status.
7. Keep loaded delivery details usable when connectivity becomes unstable.
8. Keep operational work separate from history and search.
9. Use rider-friendly language instead of database or logistics-engine terminology.
10. Prevent invalid or duplicate states at the server, not only in the UI.

## Recommended Information Architecture

```text
My Deliveries
├── Connection or route-update notice, only when needed
├── New assignment offer, only when pending
├── Current Delivery
│   ├── Work-item identity and business type
│   ├── Progress and estimated time
│   ├── Current delivery
│   ├── Contextual primary action
│   ├── Call, directions, instructions, and report issue
│   └── Expandable full delivery sequence for a batch
├── Up Next
│   └── One compact accepted assignment
└── Delivery Batches and Single Deliveries
    ├── Upcoming
    ├── History
    ├── Issues
    └── All
```

There is no separate Active tab. The active task already owns the top of the page.

Search, business type, date, status, and sorting controls belong only to the lower lists. They must never hide a current assignment or pending offer.

## Status and Classification Model

### Work-item groups

| Group | Included state | Rider-facing label | Default behavior |
|---|---|---|---|
| Needs response | Offered batch or standalone assignment requiring acceptance | New batch offer / New delivery offer | Visible above current work |
| Current | One in-progress batch or standalone delivery | In progress | Pinned and expanded |
| Upcoming | Accepted but not started | Scheduled next | Compact and sorted by schedule |
| Issue | Failed attempt or unresolved delivery exception | Delivery issue | Visible until resolved |
| History | Completed, cancelled, or rider-declined work | Completed / Cancelled / Declined | Read-only and collapsed |

### Lower-list entities

The lists use deterministic entity types:

- **Upcoming and History** contain work-item cards. A work item is either a batch or one standalone delivery.
- **Issues** contains individual unresolved delivery exceptions. A batched delivery issue shows its parent batch ID and sequence; a standalone issue shows “Single delivery.”
- A delivery issue remains visible inside its parent batch sequence and may also appear in Issues. Both views reference the same delivery and attempt IDs; they are not duplicate records.
- Declining an offer removes it from Needs response and places a read-only work-item entry in History as Declined.
- **All** contains every work item assigned or offered to the rider: pending offers, Current, Upcoming, completed, cancelled, declined, and work items containing issues. Each work item appears once in All by type and ID. Current and pending offers remain pinned above as the authoritative action surfaces; their compact All entries link back to the pinned card and do not repeat primary actions.
- Searching for an individual delivery ID in All returns its parent batch card, automatically expanded to the matching delivery. A standalone match returns its own card.
- Filtering All may hide a compact duplicate entry, but it never hides the authoritative pinned Current or pending-offer card.

### Delivery status labels

| System concept | Rider-facing label |
|---|---|
| Assigned and not started | Ready to start |
| Heading to pickup | Going to pickup |
| Arrival event recorded at pickup | Arrived at pickup |
| Picked up | Pickup confirmed |
| In transit | Going to customer |
| Arrival event recorded at drop-off | Arrived at customer |
| Awaiting proof approval | Proof submitted |
| Delivered | Delivered |
| Failed delivery attempt | Delivery issue |
| Cancelled | Cancelled |

Arrival should be recorded as a delivery event and timestamp rather than adding another canonical shipment-leg status. This reuses the existing delivery-event model and avoids expanding every status query.

### Progress rules

- Count only delivered stops as completed.
- Display proof-pending stops separately.
- A failed attempt does not mark the whole batch failed.
- A batch completes automatically after every delivery is delivered, cancelled, returned, or otherwise resolved according to existing business rules.
- A standalone delivery completes its work item when that delivery reaches a terminal resolved state.

## Single Active Work Rule

A rider may have exactly one active work item:

- one in-progress batch; or
- one in-progress standalone delivery.

Accepted assignments may remain Upcoming, but they cannot start while another work item is active.

The rule must be enforced in the server-side start transitions for both assignment types. A UI-only disabled button is insufficient.

If legacy data contains multiple active work items, the page must:

1. choose the earliest-started item as Current;
2. show a clear conflict notice;
3. prevent starting or advancing the competing work item; and
4. direct the rider to contact the dispatcher.

A dispatcher override is outside this rider-page phase.

## Business Type Filter

The lower lists include one compact mobile control:

- All businesses
- Retail
- Repair

The filter groups delivery purposes:

| Business type | Included purposes |
|---|---|
| Retail | Retail deliveries and retail returns |
| Repair | Repair pickup and repair return |
| All | Every supported purpose |

Each work item also shows a descriptive badge such as:

- Retail delivery
- Repair pickup
- Repair return

The filter does not apply to Current or pending offers because time-sensitive work must not be hidden.

## Rider Flow

### 1. Receive an assignment

The rider sees a compact offer containing:

- batch or delivery ID;
- Retail or Repair type;
- pickup and delivery areas;
- number of deliveries or stops;
- delivery window;
- estimated duration; and
- response deadline when one exists.

Primary actions are Accept and Decline. The decline-reason field appears only after Decline is chosen.

### 2. Accept an assignment

The assignment moves to Up Next.

If no work item is active, Start batch or Start delivery becomes available. If another item is active, the page explains that the current work must be finished first.

### 3. Start work

The assignment becomes Current Delivery.

For a batch, only the first actionable delivery is expanded. For a standalone assignment, the same card is used without batch progress or sequence controls.

An actionable delivery is the first stop by sequence whose server state permits a rider transition:

- Assigned
- Picked up
- In transit

The selector skips Delivered, Cancelled, Awaiting proof approval, and issue states waiting for dispatcher resolution. Skipped proof-pending and issue deliveries remain visible in the expanded sequence and Issues tab.

If an active work item has no actionable delivery:

- show “Waiting for proof approval” when proof-pending deliveries remain;
- show “Waiting for dispatcher” when unresolved issues remain; or
- show the existing terminal summary when every delivery is resolved.

These waiting states have no rider state-changing primary action.

### 4. Process the current delivery

The dominant action follows the actual state:

| Situation | Primary action |
|---|---|
| Going to pickup | Open directions |
| At pickup | I’ve arrived |
| Item received | Confirm pickup |
| Ready for drop-off | Start delivery |
| At customer | I’ve arrived |
| Handover complete | Submit delivery proof |

Call, instructions, and Report issue remain secondary actions.

The canonical transitions are:

| Input state/event | Rider action | Result |
|---|---|---|
| Assigned | Open directions | No status change |
| Assigned at pickup | I’ve arrived | Idempotent `pickup_arrived` event; status remains Assigned |
| Assigned with required pickup evidence | Confirm pickup | Picked up |
| Picked up | Start delivery | In transit |
| In transit at drop-off | I’ve arrived | Idempotent `dropoff_arrived` event; status remains In transit |
| In transit with required delivery proof | Submit delivery proof | Awaiting proof approval |
| Awaiting proof approval | No rider completion action | Authorized dispatcher approval changes status to Delivered |
| Any eligible active state | Report issue | Failed attempt/issue record using existing delivery-attempt rules |

The rider may continue to the next eligible delivery while a prior delivery is awaiting proof approval, but that prior delivery does not count as completed. The batch remains in progress until every delivery reaches a terminal resolved state.

### GPS-assisted arrival verification

Service eligibility and rider arrival use two deliberately separate distance rules:

| Rule | Purpose | Unit and default | Center |
|---|---|---|---|
| Shared service coverage | Determines whether a customer address qualifies for shop-owned pickup or delivery | Existing `coverage_radius_km` | Saved shop pin |
| Arrival check | Verifies that the rider is near the current stop when using I've arrived | New `arrival_radius_m`, default 100 m, configurable from 50-500 m | Current leg pickup or drop-off snapshot |

The shared service radius applies to retail delivery, repair pickup, and repair return. Phase 2 does not add separate pickup and delivery radii.

Logistics Settings replaces the isolated coverage number with a Delivery service area section:

- reuse the installed Leaflet integration;
- center the map on the saved shop coordinates;
- draw one circle using `coverage_radius_km`;
- redraw the circle immediately when the numeric radius changes;
- explain that customer pins inside the circle qualify for shop pickup or delivery; and
- when the shop pin is missing, disable the preview and link to Shop Settings to set it.

Shop Settings remains the single editor for the shop location. Logistics Settings receives the coordinates for display but does not create a second editable shop pin. Customer coordinates continue to come from the existing customer address map picker. The server-side coverage calculation remains authoritative; the map is a configuration preview.

Arrival verification uses the immutable coordinates already stored on the active shipment leg:

- pickup arrival targets `origin_snapshot`;
- drop-off arrival targets `destination_snapshot`; and
- later edits to a shop or customer address do not move an active delivery's target.

Phase 2 must also complete the snapshot contract for newly created legs. Every shop and customer origin or destination snapshot stores latitude and longitude from the accepted shop/customer address at creation time. This includes retail shop origins and both ends of refund-return legs, which are incomplete in legacy records. Existing legs are not guessed or backfilled; a legacy target without coordinates follows the Location unavailable exception path.

When the rider taps I've arrived, the client requests a one-time high-accuracy location and submits latitude, longitude, reported accuracy, and capture time. The server validates the rider assignment and leg state, resolves the target itself, calculates the distance, and records one of these results:

| Result | Condition | Rider behavior |
|---|---|---|
| Verified arrival | Within the configured radius with usable GPS accuracy | Record the event and expose the next contextual action |
| Outside geofence | Distance exceeds the configured radius | Require a reason, record the exception, and allow the rider to continue |
| Low accuracy | Reported GPS accuracy is too broad to verify the radius | Require a reason, record the exception, and allow the rider to continue |
| Location unavailable | Permission denied, GPS failed, or the target has no coordinates | Require a reason when online, record an unverified arrival, and allow the rider to continue |
| Offline | The request cannot reach the server | Do not claim success; retain the loaded delivery and offer Retry after reconnect |

GPS evidence is usable only when latitude and longitude are finite and in their legal ranges, accuracy is finite and between 0 and 5,000 m, accuracy does not exceed `arrival_radius_m`, and `captured_at` is no more than five minutes old or one minute in the future relative to server time. Evidence outside those rules cannot produce a Verified arrival and follows the low-accuracy or location-unavailable reason flow.

Outside or unverified reasons use rider-friendly choices:

- GPS location is inaccurate;
- Shop or customer pin is incorrect;
- Met the customer at another location;
- Road or access restriction;
- Safety concern; and
- Other, with required notes.

The `pickup_arrived` and `dropoff_arrived` events are explicitly recorded with `visibility = internal`. They store the verification result, distance, configured radius, reported accuracy, capture time, submitted coordinates, and exception reason in internal metadata. Exact rider coordinates remain internal and are not exposed to the customer.

Each arrival event is idempotent per shipment leg and event type. A repeated tap or retry returns the existing event and cannot create duplicate arrivals. Arrival does not change the canonical shipment-leg status.

Dispatcher shipment and batch details reuse the delivery timeline to show:

- Verified arrival, distance, and timestamp;
- Outside geofence, distance, and required reason; or
- Location unavailable or low accuracy with the rider's explanation.

An outside or unverified arrival is visibly flagged for review but does not automatically fail the delivery or block the remaining batch.

### 5. Complete a delivery

The rider receives a short success confirmation. In a batch, the next actionable delivery automatically becomes current and the progress updates. The rider does not return to the batch list between stops.

For a standalone delivery, the current work item closes and the next accepted assignment becomes eligible to start.

### Customer-visible proof of delivery

After authorized proof approval changes the leg to Delivered, the owning customer sees **View proof of delivery** beside the Delivered update on the existing shipment tracking page.

A customer-visible proof must meet every condition:

- the proof belongs to a leg in the customer's shipment;
- `handoff_type` is `delivery` or `receive`;
- `proof_type` is `photo`;
- `review_status` is `approved`;
- the leg is Delivered; and
- the original file still exists.

Pending, rejected, pickup, failed-attempt, and internal issue evidence must never appear as proof of delivery. Existing failed-attempt evidence remains a separate customer-safe tracking feature and is not reused as successful-delivery proof.

The proof viewer provides:

- a customer-safe image with full-screen viewing and zoom;
- an accessible download action for that customer-safe image;
- delivered date and time;
- customer-visible delivery location from the destination snapshot;
- tracking number; and
- delivery status.

These details are rendered as a UI overlay or adjacent details. The original uploaded file is preserved unchanged in private storage for audit. The customer image response is generated from that private original with EXIF and other embedded metadata stripped; the sanitized response is not stored as a permanently watermarked duplicate. The download action serves the same sanitized response. Raw rider GPS coordinates and internal geofence exception reasons are not customer-visible.

Successful handoff proof uploads use server-generated paths on the private disk. Rider-facing HTTP requests cannot submit or select `file_path`; they must upload the file. Existing successful handoff files under public storage must be moved to private storage and their public copies removed before being eligible for customer POD viewing.

The existing customer shipment ownership check protects both the tracking payload and the private, sanitized proof-file response. Retail orders, order returns, and repair shipments use the same rule. A missing or unreadable approved file shows Proof unavailable without rendering a broken image or exposing its storage path.

If historical data contains multiple eligible proofs for one Delivered leg, select deterministically: prefer `delivery` over `receive`, then order by `reviewed_at` descending and proof ID descending.

### 6. Report an issue

The rider chooses a rider-friendly reason:

- Customer unavailable
- Incorrect address
- Customer refused
- Item damaged
- Unsafe location
- Vehicle problem
- Other

Photo or notes are requested only when required. The affected delivery moves to Issues, while other eligible deliveries in the batch may continue according to the existing retry and dispatcher rules.

## Mobile-First Wireframe Description

Reference mockup: `.superpowers/mockups/my-deliveries-mobile.svg`

The mockup represents the target experience. Its I’ve arrived control belongs to Phase 2 and must not appear in the Phase 1 implementation.

### Header

- Page title at the top.
- Small connection/sync indicator below the title.
- No desktop-only search bar in the primary task area.

### Current Delivery card

- Strongest border and elevation on the page.
- Business type and text status chips.
- Work-item ID, completed count, ETA, and labelled progress indicator.
- Current sequence shown as “Current delivery · 3 of 5,” not “Leg #3.”
- Customer or merchant name, address, and instructions.
- Full-width primary action.
- Secondary Call and Report issue actions.
- “View all 5 deliveries” expands the full sequence inline.

### Expanded batch sequence

Each delivery row shows:

- sequence number;
- delivery ID;
- customer or merchant;
- short location;
- text status plus icon;
- primary action only for the current delivery.

Completed deliveries are collapsed and visually quiet. Upcoming deliveries show only essential information.

### Up Next

One compact card shows:

- Batch or Single delivery;
- Retail or Repair;
- schedule;
- stop count;
- approximate duration; and
- View details.

Additional upcoming work remains in the lower list.

### Lower lists

Tabs:

- Upcoming
- History
- Issues
- All

Controls:

- Business type
- Date
- Status where relevant
- Search

History may use a status sub-filter for Completed and Cancelled instead of adding more top-level tabs.

## Reducing Visual Clutter

| Recommendation | Problem solved | Workflow improvement |
|---|---|---|
| Pin one Current Delivery | Competing next actions | Rider immediately knows what to do |
| Expand only the current delivery | Too much customer and address data | Faster scanning and shorter page |
| Show only one Up Next card | Upcoming work overwhelms active work | Preserves awareness without distraction |
| Move completed/cancelled work to History | Long operational list | Keeps active area short |
| Reveal decline reason on demand | Permanent low-value form field | Reduces visual noise |
| Use contextual primary actions | Multiple equal buttons | Prevents invalid next-step choices |
| Keep search and filters in lower lists | Controls compete with current work | Separates execution from lookup |
| Use text labels with icons | Status chips depend on color | Faster and accessible recognition |
| Replace “Leg” with “Delivery” or “Stop” | System terminology | Matches rider mental model |

Bulk status actions should not appear in the default mobile workflow. They increase error risk and conflict with the one-current-delivery model. Existing bulk actions may remain unavailable on the redesigned rider page unless a validated operational need requires them later.

## Empty, Loading, Error, and Offline States

### Empty

- No current work: “You have no active delivery.”
- Accepted upcoming work exists: show the next scheduled assignment and its start availability.
- No assignments: “No deliveries assigned yet. New work will appear here.”
- Filter has no matches: retain filters and offer Clear filters.

### Loading

- Preserve page structure with a current-card skeleton and two compact list skeletons.
- Do not replace the entire page with a spinner.

### Error

- Keep the last successfully loaded route visible.
- Show a concise retry banner.
- Failed actions return to their previous enabled state and display a plain-language error.

### Offline

For the first phase:

- detect loss of connectivity;
- keep already-loaded address, phone, instructions, and sequence visible;
- clearly label the page Offline with the last successful sync time;
- prevent repeated submission while an action is unresolved; and
- allow the rider to retry when connectivity returns.

A durable offline mutation queue is a long-term improvement because it requires conflict resolution and persistent idempotency guarantees. The initial redesign must not claim that an action is saved when it has not reached the server.

## Stale, Duplicate, and Route-Change Behavior

- Use batch ID and delivery ID as stable identities.
- Refreshes replace existing records rather than appending them.
- Completed, rejected, or cancelled work leaves the active query immediately.
- Server state remains authoritative after reconnect or reload.
- An action already accepted by the server returns its existing result instead of creating another event.
- If a record changes while an action is open, refresh the work item and explain that the delivery was updated.
- Active-route editing and rider acknowledgement are deferred. During this phase, routes may be reordered only while still draft, matching the existing batch service.

## Accessibility

- Minimum body text: 16 px for primary operational content; 14 px for supporting metadata.
- Minimum touch target: 44 × 44 CSS pixels with at least 8 px separation.
- Text and interactive controls meet WCAG AA contrast; normal text requires at least 4.5:1.
- Status uses text and icon/shape in addition to color.
- Progress includes visible text and an accessible progressbar name and value.
- Focus order follows the visual task sequence.
- Every icon-only control has an accessible name.
- Dynamic completion, errors, offline state, and route refreshes use an `aria-live` announcement.
- Primary actions remain reachable with one hand and do not require horizontal scrolling.
- Avoid low-contrast gray text for addresses and instructions used outdoors.
- Destructive or irreversible actions require confirmation; routine progress actions do not add unnecessary confirmation taps.
- Map configuration always has an equivalent labelled numeric radius input and a textual coverage summary.
- The proof viewer provides meaningful alternative text, keyboard-accessible close and download controls, and does not place essential metadata only over the image.

## Edge Cases

- No assigned work.
- One standalone delivery.
- One batch with one delivery.
- Many accepted future batches.
- Legacy data with multiple active work items.
- Batch with zero deliveries.
- Missing customer name, phone, address, or instructions.
- Proof submitted but not approved.
- Failed attempt that may be retried.
- Delivery cancelled or reassigned while the page is open.
- Batch rejected after a stale page remains open.
- Network lost before, during, or after a status action.
- Duplicate tap or retry after a timeout.
- Shop pin or customer pin is missing.
- Rider denies location permission or the device has no geolocation support.
- GPS accuracy is wider than the configured arrival radius.
- Rider is outside the arrival geofence and must provide a reason.
- Location is captured while offline but the arrival event is not submitted.
- Delivery proof is pending, rejected, missing from storage, or belongs to another customer.
- More than one historical proof exists; only the deterministic approved proof for the delivered leg is eligible.
- Delivery date or window already passed.
- Unknown or newly introduced status.
- Retail and Repair items in the same lower list.
- No results from a business-type or date filter.
- Completed delivery still present in cached data.

Unknown states must render as “Status unavailable” with View details rather than disappearing or exposing raw enum text.

## Deterministic Ordering

- Pending offers: response deadline first when present, then `offered_at` ascending, then work-item ID ascending.
- Current conflict fallback: `started_at` ascending, then work-item type, then ID ascending.
- Upcoming: delivery date ascending, Morning before Afternoon, accepted/assigned time ascending, then ID ascending.
- Issues: latest attempt time descending, then delivery ID descending.
- History: terminal timestamp descending, falling back to `updated_at`, then ID descending.
- All: Current, Needs response, Upcoming, unresolved non-current work, then History; apply the corresponding group tie-breaker within each rank.

These rules prevent refreshed data with equal dates from changing order unexpectedly.

## Current vs Proposed Experience

| Current | Proposed |
|---|---|
| Batches stack above a separate shipment list | One unified task-first page |
| Each batch can show a next stop | One current delivery across all work |
| Offered, accepted, and active cards look similar | Distinct offer, current, upcoming, issue, and history groups |
| Standalone and batch work use different presentation paths | Same execution card with type-specific details |
| Secondary information is expanded repeatedly | Only the current delivery is expanded |
| Search/filter scope is unclear | Controls belong to lower lists only |
| Proof pending may count as complete | Only delivered items count as complete |
| Raw operational terms appear | Rider-friendly labels |
| Long list grows continuously | Compact upcoming list and paginated history |
| UI permits competing actions | Contextual primary action and server guard |

## Priorities

### High impact, low effort

- Pin one Current Delivery.
- Split offer, current, upcoming, issues, and history.
- Collapse non-current deliveries.
- Rename system terms.
- Add Retail/Repair filtering to lower lists.
- Correct progress calculation.
- Remove completed/cancelled records from active rendering.
- Hide decline reason until requested.

### High impact, medium effort

- Unify batch and standalone work in the rider read model.
- Enforce one active work item at both start transitions.
- Add arrival events and timestamps.
- Add one-time GPS verification with a configurable 100 m default arrival radius.
- Add a Leaflet preview for the existing shared shop service radius.
- Expose approved proof of delivery to the owning customer.
- Add contextual action sequencing.
- Add automatic batch completion.
- Add issue reporting and resolution-aware progression.
- Add accessible offline and stale-action handling.

### Long-term

- Durable offline mutation queue.
- Active-route changes with rider acknowledgement.
- Live rider location.
- Automatic route optimization.
- Predictive ETA and delay alerts.
- Offer expiration.

## Implementation Phases

The target design above is intentionally broader than the first code change. Planning and implementation must proceed in these independently deployable slices.

### Phase 1 — Task-first rider page

This is the scope of the next implementation plan:

- build a deterministic rider read model for pending offers, one current work item, upcoming work items, history, and existing unresolved issues;
- unify batch and standalone presentation without creating synthetic batches;
- implement the Current Delivery, Up Next, and lower-list hierarchy;
- add All/Retail/Repair filtering to lower lists;
- expand batch deliveries inline and collapse non-current details;
- correct progress so only Delivered counts as completed;
- reuse existing rider actions and proof transitions;
- enforce the one-active-work rule in both existing start paths;
- prevent active, completed, cancelled, declined, and stale records from appearing in the wrong group;
- treat standalone work as a direct assignment because Phase 1 does not add standalone offer/accept/decline endpoints;
- detect legacy multiple-active conflicts and disable their rider mutations in the page;
- retain loaded details and show a basic browser offline/online notice; and
- add focused automated coverage for classification, progress, filtering, and start guards.

Phase 1 does **not** show the new I’ve arrived action until the arrival event endpoint exists.

### Phase 2 — Arrival and issue workflow

- Add idempotent pickup-arrived and dropoff-arrived events with one-time GPS evidence.
- Add `arrival_radius_m` to Logistics Settings with a 100 m default and 50-500 m range.
- Keep the existing `coverage_radius_km` as one shared service radius for pickup and delivery.
- Add a Leaflet service-area preview centered on the saved shop pin without duplicating shop-location editing.
- Complete shop/customer coordinates in every newly created shipment-leg snapshot.
- Expose I've arrived contextually for both batched and standalone deliveries.
- Allow outside-geofence, low-accuracy, and unavailable-location arrivals only with a required reason.
- Show verified and exception arrival details in the existing dispatcher delivery timeline.
- Improve rider issue reporting with conditional evidence.
- Add dispatcher-facing resolution instructions consumed by the rider page.
- Expose only approved delivery or receive proof to the owning customer after the leg is Delivered.
- Move successful handoff proofs behind private, ownership-checked responses and reject client-selected file paths.
- Strip embedded image metadata from customer responses while preserving the private original.
- Render proof date/time, destination, tracking number, and status as UI details while preserving the original image.

### Phase 3 — Completion and synchronization hardening

- Verify or add automatic batch completion across all terminal resolution types.
- Harden idempotency for every rider transition.
- Add server-side arbitration for advancing work when corrupt legacy data already contains multiple active items.
- Add clearer stale-record refresh behavior and conflict messaging.

### Phase 4 — Advanced operations

- Durable offline mutation queue.
- Active-route changes with rider acknowledgement.
- Live location, predictive ETA, and route optimization.

Each phase requires its own implementation plan and verification. Completing Phase 1 must not imply that later-phase controls shown in the target mockup are already functional.

## Phase 2 Out of Scope

- Continuous or background rider tracking.
- Customer access to raw rider GPS coordinates or internal geofence exceptions.
- Polygon service zones or route-distance coverage.
- Separate pickup and delivery service radii.
- Per-customer arrival radii.
- Editing the canonical shop pin from Logistics Settings.
- Permanent image watermarking or storing a second customer proof image.
- Durable offline arrival submission.
- Automatic route optimization or predictive ETA.

## Phase 2 Acceptance Criteria

1. Logistics Settings shows one Leaflet service-area preview using the saved shop pin and existing shared `coverage_radius_km`.
2. The same service radius governs shop-owned retail delivery, repair pickup, and repair return eligibility.
3. Logistics Settings stores one arrival radius in meters, defaults it to 100 m, and validates 50-500 m.
4. Every newly created leg snapshot includes its shop/customer coordinates; pickup and drop-off arrival targets come from those snapshots rather than client-supplied target coordinates.
5. I've arrived appears only for the assigned rider in an eligible active leg state.
6. Only fresh, valid GPS evidence whose accuracy does not exceed the configured arrival radius can produce a Verified arrival.
7. A verified pickup or drop-off records one explicitly internal, idempotent arrival event without changing the shipment-leg status.
8. Outside-geofence, low-accuracy, unavailable-location, and legacy missing-coordinate arrivals require a reason and remain non-blocking.
9. Repeated arrival taps or retries return the existing event rather than creating duplicates.
10. Offline arrival attempts are not shown as saved and provide a clear retry path.
11. Dispatcher delivery details distinguish Verified arrival, Outside geofence, Low accuracy, and Location unavailable using text and icons.
12. The owning customer sees View proof of delivery only after an approved delivery or receive photo belongs to a Delivered leg.
13. Successful handoff proof uploads use server-generated private paths, and legacy public handoff files are removed from public storage when migrated.
14. Pending, rejected, pickup, failed-attempt, internal issue, cross-shipment, and cross-customer files cannot be fetched through the POD endpoint.
15. The proof viewer renders date/time, destination summary, tracking number, and status while preserving the private original and stripping embedded metadata from the customer response.
16. When multiple eligible proofs exist, delivery precedes receive, then newest `reviewed_at`, then highest proof ID.
17. Missing approved proof files degrade to Proof unavailable without exposing storage paths.
18. Batched and standalone deliveries use the same arrival and customer-proof rules.

## Phase 1 Out of Scope

- Dispatcher page visual redesign.
- New arrival-event endpoints and the I’ve arrived action.
- New issue-reporting or dispatcher-resolution behavior.
- New standalone offer, acceptance, or decline behavior.
- Server-side repair/arbitration of pre-existing multiple-active records beyond disabling rider mutations in the Phase 1 UI and preventing new starts.
- Changes to terminal-state and automatic batch-completion rules.
- Custom map rendering.
- Automatic route optimization.
- Live GPS tracking.
- A synthetic database batch for standalone work.
- Durable offline mutation queue.
- Active route editing after the rider starts.
- New bulk-completion behavior.

## Phase 1 Acceptance Criteria

1. The rider sees at most one Current Delivery.
2. The Current Delivery may represent a batch or standalone assignment.
3. Inside the Current Delivery execution area, at most one state-changing primary action is shown. Waiting states may show none. Open directions may appear as a non-state-changing navigation action, and the separate pending-offer region may show Accept and Decline without changing which delivery is current.
4. Only delivered stops count toward completed progress.
5. Current work and pending offers remain visible regardless of list filters.
6. Upcoming, History, Issues, and All lists support Retail/Repair filtering.
7. A batch exposes all deliveries through one inline expansion.
8. When one delivery becomes Delivered, only that delivery advances; the page updates progress and promotes the next eligible delivery.
9. Starting a batch is rejected by the server when a standalone delivery is active, and starting a standalone delivery is rejected when a batch is active.
10. Completed, rejected, and cancelled work does not remain in the active area.
11. Status is communicated with text and icon/shape, not color alone.
12. Essential loaded details remain readable when the browser goes offline.
13. Repeated batch or standalone start requests do not create duplicate active transitions.
14. Existing dispatcher pages continue to own single assignment and batch creation workflows.
