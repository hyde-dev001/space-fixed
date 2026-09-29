# Return-to-Shop Rider Handoff Design

## Problem

A `return_to_shop` leg currently reuses the outbound delivery controls. Riders can therefore see and submit "Delivered successfully" or "Couldn't deliver" after starting the return. A failed-attempt submission can cancel the return assignment and schedule another attempt, even though the parcel is already being returned to the shop.

## Approved workflow

1. A return leg never exposes customer delivery outcomes or failed-delivery reporting.
2. At the shop, the rider uploads a return handoff photo and confirms the handoff.
3. The return stays open while the proof is marked `rider_confirmed`.
4. An authorized shop/dispatcher user confirms the physical parcel receipt.
5. The existing receipt service closes logistics custody and marks the original leg returned. Detailed item inspection and resellable/damaged inventory disposition continue in the refund workflow before Finance approval.
6. Vehicle or emergency problems remain delivery incidents; they are not failed customer delivery attempts.

## Safety rule

`ShipmentLegService::recordFailedAttempt` must reject `return_to_shop` legs before creating an attempt or changing assignments, batches, or leg status. This protects the workflow from stale or custom clients as well as the UI.
