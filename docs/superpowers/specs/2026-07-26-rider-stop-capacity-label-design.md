# Rider Stop Capacity Label Clarification

## Problem

The logistics setting says "Daily capacity per rider," while the system
actually measures delivery stops. A retail order with five shoe pairs going to
one address correctly consumes one stop, but the ambiguous label makes that
look like a quantity-counting bug.

## Design

Keep the existing stop-based scheduling and capacity enforcement unchanged.
Clarify the UI copy:

- Rename the setting to "Daily delivery stops per rider."
- Explain that one order/address counts as one stop regardless of item quantity.
- Change rider workload text from "used today" to "stops used today."
- Change the workload equation from "`N` used" to "`N` stops used."

## Error Handling

No request, validation, database, or error-handling behavior changes.

## Verification

Update the existing Settings and Batches component tests to assert the clarified
labels and explanation. Run both focused frontend test files and the production
build.

## Scope

Do not count shoe quantity as route capacity and do not add package, weight, or
volume limits. Those are separate load-capacity features.
