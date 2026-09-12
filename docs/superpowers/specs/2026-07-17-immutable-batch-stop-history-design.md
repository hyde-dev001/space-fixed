# Immutable Batch Stop History Design

## Problem

Batch History currently depends on `shipment_legs.delivery_batch_id` or a snapshot created only during cancellation. Failed-attempt processing intentionally clears `delivery_batch_id` so a delivery can be retried or re-batched. Once a leg moves, an older batch can appear empty. A history record must not change when its deliveries are reused.

## Data Model

Add a nullable JSON `stop_snapshot` column to `delivery_batches`, cast to `array` and added to `DeliveryBatch::$fillable`. Entries are ordered by `stop_sequence`, then leg ID, and use this exact contract:

- `id`, `sequence`, `leg_type`, `status`
- `origin_snapshot`, `destination_snapshot`
- `scheduled_delivery_date` (`Y-m-d` or null), `delivery_window`, `schedule_status`
- `stop_sequence`, `urgent_at` (ISO-8601 or null)
- `shipment`: `id`, `source_type`, `source_id`

`BatchDispatchService` uses one private serializer for every runtime write. Existing `cancelled_stops` remains for backward compatibility and restore behavior.

## Snapshot Lifecycle

`BatchDispatchService` owns snapshot synchronization:

- Creating a Draft saves its ordered stops to `stop_snapshot`.
- Reordering or removing Draft stops refreshes `stop_snapshot` after the mutation.
- Changing urgency while a stop belongs to a Draft refreshes `stop_snapshot`; every Draft mutation affecting a serialized field must do the same.
- Offering a batch performs the final refresh and freezes the snapshot.
- Rejecting an offer back to Draft intentionally reopens the same lifecycle. A later Draft mutation or re-offer may replace the prior offered snapshot because the rejected batch is not a History entry.
- Cancelling, accepting, starting, completing, failed attempts, retries, and re-batching never rewrite the frozen snapshot.
- Restoring a cancelled batch intentionally removes that cancellation from History and begins a new lifecycle for the same batch. Restore selects the first non-empty source (`stop_snapshot`, then `cancelled_stops`) and keeps the existing all-or-nothing validation: every selected leg must exist, belong to the shop, be pending, and have no batch. A validation failure does not fall through to the older source. After successful reattachment, the Draft snapshot is refreshed.

The snapshot represents the route membership and order when it left Draft. It is not a live status feed.

## Read Path and UI

The batches page returns `stop_snapshot` with each batch. For `completed` and `cancelled` History batches, `BatchCard` and read-only `BatchWorkspace` select the first non-empty source: `stop_snapshot`, then `cancelled_stops`, then live `legs`. Active Draft/Offered/Accepted/In-Progress batches use live legs so operational status remains current.

Both History components derive displayed stop count, urgency count, row total, and rows from that one selected array. Both show “Historical stop details unavailable” when every source is empty.

## Legacy Records

The migration backfills every reconstructable batch. It selects the first non-empty source: `cancelled_stops`, then currently linked live legs. Both sources are normalized into the exact field/date contract and ordering above; raw legacy `cancelled_stops` JSON is never copied unchanged. Live-leg backfill explicitly covers existing Draft, Offered, Accepted, In-Progress, Completed, and Cancelled batches before any later detachment. Existing batches with neither source cannot be reconstructed exactly and use the explicit unavailable state.

## Error Handling and Safety

Snapshot writes occur inside the existing batch transactions. Tenant and batch-state checks remain unchanged. Restore continues rejecting stops that are no longer available, preventing a historical snapshot from overwriting current ownership.

## Verification

- Migration test: legacy cancelled snapshots and live stops for existing Draft, Offered, Accepted, In-Progress, Completed, and Cancelled batches are normalized/backfilled with the stable ordered contract.
- Service test: Draft creation, reorder, removal, and offer produce the correct ordered snapshot; post-offer leg movement and cancellation do not mutate it.
- Service test: Draft urgency change refreshes the snapshot and immediate cancellation retains that urgency.
- Service test: reject → Draft edit → re-offer refreshes the snapshot for the reopened lifecycle.
- Service test: restore prefers `stop_snapshot`, falls back only when it is null/empty, and stays all-or-nothing when a selected stop conflicts.
- Page/UI test: completed and cancelled History batches with empty live legs render `stop_snapshot`, including saved stop count, urgent count, and order row.
- UI test: legacy fallback uses `cancelled_stops`, then live legs, and both History components show the unavailable message only when all sources are empty.
- Run focused logistics backend tests, Batches UI tests, and the production build.
