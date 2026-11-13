# Flexible Premium Plans and Connected Showroom Design

## Goal

Allow super admins to create, edit, archive, and reactivate premium plans while keeping Premium Benefits, subscription entitlements, and Virtual Showroom capacity synchronized. Plans support 1–150 showroom slots and editable ordered benefit bullets.

## Decisions

- Existing and new plans are editable.
- Plans are archived instead of deleted; archived plans remain linked to historical and active subscriptions.
- Archived plans are hidden from Premium Benefits and cannot be purchased, upgraded to, or selected for renewal.
- Archived plans can be reactivated.
- Slot-limit increases apply immediately to active subscriptions on the edited plan.
- Slot-limit decreases do not reduce active subscriptions; the lower limit applies on renewal.
- Benefits are stored as an ordered JSON array on `premium_plans`.
- The maximum plan capacity is 150 slots.
- Capacities from 1–84 retain the current single-room showroom unchanged.
- Capacities from 85–150 use two equal-size connected rooms and distribute slots as evenly as possible.

## Data Model

Add a nullable JSON `benefits` column to `premium_plans`. The model casts it to an array. Existing seeded plans receive the current common premium-benefit bullet text so the UI does not lose content after migration.

Existing fields remain the source of truth for plan name, code, description, price, duration, slot limit, and status. `shop_owner_subscriptions.showroom_slot_limit` remains the entitlement snapshot used by upload enforcement and the showroom.

## Admin Plan Management

The Subscription Management page receives all plans, including archived plans, plus each plan's active-subscriber count. A responsive Plan Management section appears above the existing subscription metrics and table.

Each plan card shows:

- name and plan code;
- description and price;
- duration and showroom-slot limit;
- ordered benefits;
- active or archived status;
- active-subscriber count;
- Edit and Archive/Reactivate actions.

Create and Edit share one modal. Fields are name, unique plan code, description, price, duration in days, showroom slots, status, and benefit bullets. The plan code is set on creation and read-only afterward because subscriptions and payment records retain it. Benefits can be added, edited, removed, and moved up or down without a drag-and-drop dependency.

The slot field explains the layout boundary: 1–84 uses the current room; 85–150 uses two connected rooms.

## Plan Mutation Rules

Create, edit, archive, and reactivate use super-admin-only routes and server-side validation. Plan changes run in a database transaction.

When an edit raises a plan's slot limit, active/showroom-entitled subscriptions linked to that plan are updated to the new higher snapshot immediately. When an edit lowers the limit, active subscription snapshots remain unchanged. Renewal already copies the current plan limit, so the decrease applies at renewal.

Editing duration or price never rewrites an active subscription's paid amount or current end date. The new values apply to new purchases, upgrades, downgrades when effective, and renewals.

Archiving changes only the plan status. It does not cancel subscriptions or remove showroom access. Purchase and plan-change queries continue to select active plans only.

## Premium Benefits

The page continues loading active plans from the backend. It renders each plan's current name, description, price, duration, slot capacity, and ordered `benefits` array. Hard-coded plan-specific benefit bullets are removed. Empty benefit arrays render no artificial placeholders.

All checkout, upgrade, and downgrade actions continue using plan IDs/codes supplied by the backend. No pricing or entitlement logic moves into the frontend.

## Connected Virtual Showroom

For capacities up to 84, the current showroom geometry, shelf definitions, controls, and product placement remain unchanged.

For capacities from 85 through 150:

- create two rooms with the same dimensions and visual design as the current room;
- distribute entitlement capacity as `ceil(limit / 2)` for room 1 and `floor(limit / 2)` for room 2;
- examples: 85 becomes 43/42, 100 becomes 50/50, and 150 becomes 75/75;
- assign showroom products sequentially across room 1, then room 2;
- provide a visible door from room 1 to room 2 and a return door from room 2 to room 1;
- show the current room and its slot range in the HUD;
- render the active room's product displays while keeping navigation state local to the showroom component.

The plan capacity remains the total number of allowed showroom products. Empty entitled slots are capacity, not placeholder products, and therefore do not create unnecessary meshes.

## Validation and Errors

Server validation requires:

- unique nonempty plan code;
- nonempty name;
- nonnegative numeric price;
- duration from 1 through 3650 days;
- showroom capacity from 1 through 150;
- benefits as at most 20 nonempty strings, each no longer than 200 characters.

Validation errors return to the modal beside the affected fields. Mutations preserve existing data on failure. Archive/reactivate actions require confirmation and show the existing success/error flash messages.

## Compatibility

The existing subscription metrics, filters, details modal, cancel flow, checkout, PayMongo webhook, proration, renewal, upgrade/downgrade, showroom entitlement API, and product-slot enforcement remain in place. They continue using the plan relation and subscription snapshot appropriate to their existing lifecycle.

No permanent plan deletion is added. No benefit-table model, drag-and-drop library, or new state-management dependency is introduced.

## Testing

Backend feature tests cover:

- create, edit, archive, and reactivate authorization and validation;
- active-plan visibility in Premium Benefits;
- JSON benefit ordering;
- immediate propagation of slot increases;
- preservation of active subscription limits on decreases;
- renewal using the lower current plan limit;
- archived plans remaining valid for current subscribers but unavailable for new selection.

Frontend/unit checks cover room calculation boundaries and distribution: 84 uses one unchanged room, 85 produces 43/42, 100 produces 50/50, and 150 produces 75/75. Existing TypeScript/build checks and focused premium/subscription tests must pass.

## Acceptance Criteria

- A super admin can create and manage plans without changing source code.
- Existing plans can be edited, archived, and reactivated.
- Premium Benefits reflects active plan edits and ordered benefits.
- A plan cannot exceed 150 showroom slots.
- Slot increases are immediately available to active subscribers; decreases wait for renewal.
- The existing showroom is unchanged for 1–84 slots.
- An 85–150 slot entitlement provides two equal-size connected rooms with an even slot split and working doors.
- Existing premium purchase, subscription, and showroom enforcement flows continue to work.
