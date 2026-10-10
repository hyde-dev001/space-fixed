# Rider Batch Checklist Design

## Goal

Replace the rider's long batch list with a compact route checklist that makes the next stop obvious and automatically marks finished rider work.

## Status Rules

- A stop is complete for the rider when its leg status is `awaiting_proof_approval` or `delivered`.
- Completion is derived from the existing leg status; no checkbox state or database field is added.
- The client sorts stops by `stop_sequence`, with null sequences last and leg ID as the stable tie-breaker. The first incomplete stop is the next stop.
- Completed stops remain in their original order.
- `awaiting_proof_approval` displays `Proof submitted`; `delivered` displays `Delivered`.

## Layout

- Each batch shows its delivery date/window, status, a progress bar, and `completed of total` count.
- The next stop is always expanded and highlighted.
- Other stops are compact and individually expandable through Open delivery. Only one non-next stop may be expanded; refresh resets expansion to the current next stop.
- Completed stops are dimmed with a green check and their status-specific completion label.
- An expanded stop shows stop number, receiver, address, phone, instructions, and actions for Call and Directions. It also preserves the existing applicable Confirm pickup and Out for delivery controls; existing batch-level Accept, Reject, and Start batch controls remain unchanged.
- The layout is stacked and responsive, avoiding fixed heights and large empty areas.

## Data Flow

The existing rider deliveries response supplies batches with ordered legs. The UI calculates completed count and next stop from each leg status. After proof submission, the normal Inertia refresh returns `awaiting_proof_approval`, which checks the stop and advances the highlight automatically.

Directions uses the leg address in a standard Google Maps search URL. Call uses a `tel:` URL. Open delivery expands that checklist stop and exposes its details and existing workflow controls.

## Error and Empty States

- Missing receiver, address, or phone displays `Not provided`; Call is hidden without a phone and Directions is hidden without an address.
- A batch with no legs displays `No stops in this batch` and `0 completed`, with an empty progress bar.
- A fully completed batch displays full progress, `All stops completed`, and no next-stop highlight.

## Verification

- Component test: proof-submitted and delivered legs render checked with their correct labels.
- Component test: stops sort deterministically and the first unfinished stop is highlighted as next.
- Component test: proof refresh advances the next-stop highlight and progress count.
- Component test: zero-leg and fully completed batches render without invalid progress.
- Component test: missing contact fields show fallbacks and hide unavailable actions.
- Component test: Accept, Reject, Start, Confirm pickup, and Out for delivery remain available in their existing states.
- Run the focused frontend tests and production build.
