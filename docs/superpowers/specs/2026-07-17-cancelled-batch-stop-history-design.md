# Cancelled Batch Stop History Design

## Problem

Cancelling a batch detaches its live delivery legs so they can return to the available pool. The batch correctly saves those stops in `cancelled_stops`, but `BatchCard` still renders `batch.legs`. As a result, expanding a cancelled batch in History shows no deliveries.

## Design

`BatchCard` will use the existing `BatchWorkspace` fallback rule: use `cancelled_stops` only when the batch is cancelled and the snapshot contains stops; otherwise use live `batch.legs`. The selected list will drive both the urgent count and expanded stop rows, preserving legacy cancelled batches whose snapshot is null or empty.

No database, API, or cancellation-flow changes are needed. `BatchWorkspace` already follows this snapshot pattern.

## Verification

Add one UI regression test that expands a cancelled History batch whose live legs are empty and confirms its saved urgent delivery is visible and the card reports `1 urgent`. Run the focused Batches test file and the production build.
