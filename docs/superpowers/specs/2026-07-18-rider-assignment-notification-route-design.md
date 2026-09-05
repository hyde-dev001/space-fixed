# Rider Assignment Notification Route Fix

## Problem

Individual delivery-assignment notifications send riders to `/erp/logistics/shipments`, which requires dispatcher permission and returns 403. Riders work from `/erp/logistics/deliveries`.

## Design

- Send future individual rider-assignment notifications to `/erp/logistics/deliveries`.
- Preserve dispatcher-only access to the shipments page.
- When an authorized rider opens the legacy shipments URL, redirect them to deliveries; users without either rider or dispatcher access remain forbidden.
- Cover the new notification URL, authorized legacy redirect, and unauthorized 403 with feature tests.

No database backfill is needed because the guarded legacy redirect keeps existing notification records usable.
