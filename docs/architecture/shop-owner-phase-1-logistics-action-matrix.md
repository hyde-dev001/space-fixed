# Shop-owner Phase 1: Logistics action-capability matrix

Status: baseline contract for the phase-1 enforcement migration.

This document separates the authenticated `ShopOwner` guard from the employee
`User` guard and assigns responsibility to an explicit Logistics action. It is
an inventory and policy boundary, not a claim that every route has already
been migrated. The route inventory below is based on `routes/web.php`,
`routes/shop-owner-erp.php`, and the `api/logistics` route list.

## Boundary rules

- The tenant is the `Shipment.shop_owner_id` (or the equivalent
  `shop_owner_id` on a batch, incident, rider profile, or settings record).
  A request fails generically when the actor and source record are from
  different shops.
- A `ShopOwner` actor is an owner-context actor. The owner guard does not
  grant every Logistics mutation. Owner authority is limited to the explicit
  action allowlist below and to the shop's enabled `logistics` module.
- An employee is a `User` whose `shop_owner_id` matches the tenant and whose
  existing Spatie permission grants the named capability. Employee access is
  not inferred from the owner guard or from a page being visible.
- A custody action resolves the authoritative `RiderProfile` by
  `linked_type`/`linked_id`, requires `active = true` and a non-`inactive`
  availability state, and then requires an `assigned` or `accepted`
  `DeliveryAssignment` for the exact `shipment_leg_id`.
- The `logistics` module is eligible for approved company shops whose business
  type is `retail`, `repair`, or `both`; its persisted `ShopOwnerModule` row
  must also be enabled. This module flag is separate from the shipment source
  classification returned by `ShopOwner::logisticsModules()`.
- A proof submitter is not automatically its reviewer. The enforcement
  boundary must persist the proof submitter identity and reject a review by
  the same actor.

The canonical action enum is:

| Action | Responsibility boundary |
| --- | --- |
| `assign_rider` | Owner dispatch authority or an employee with `assign-logistics-deliveries`; source leg must be assignable. |
| `schedule_delivery` | Owner dispatch authority or an employee with `assign-logistics-deliveries` / `manage-logistics-batches`; source leg must be pending or assigned. |
| `resolve_exception` | Owner exception authority or an employee with `resolve-logistics-exceptions`; source leg/incident must be in an exception state. |
| `submit_proof` | The authenticated, linked rider only. A linked owner-rider is supported by the policy contract; employee riders also need `record-logistics-proof`. |
| `review_proof` | Owner review authority or an employee with `approve-proof-of-delivery` / the existing dispatcher review capability; the delivery proof must be pending on an awaiting-proof leg. |
| `confirm_return_receipt` | Owner or an explicitly authorized employee reviewer after the assigned rider has confirmed the return handoff. |

The enum intentionally does not invent new keys for administration and batch
CRUD in this phase. Those routes are still listed below with their existing
capability names so the later controller migration cannot silently omit them.

## Read and monitor surfaces

Reads are tenant-scoped and may be visible to an owner or to an employee with
the page/read capability. They do not grant any mutation listed later.

| Route | Tenant/source rule | Intended UI context | Current note |
| --- | --- | --- | --- |
| `GET /api/logistics/dashboard-stats` | Authenticated employee's shop | Logistics dashboard | Legacy user route; keep read scope separate from mutation policy. |
| `GET /api/logistics/shipments`, `GET /api/logistics/shipments/{shipment}` | `Shipment.shop_owner_id` | Shipments | API data for owner and ERP pages. |
| `GET /api/logistics/batches`, `GET /api/logistics/batch-suggestions` | Batch and candidate legs belong to the shop | Batches / dispatch planning | Current `dispatcherShop()` has a broad owner bypass. |
| `GET /api/logistics/riders` | `RiderProfile.shop_owner_id` | Rider administration | Current owner bypass is in `RiderProfileController::authorizedShop()`. |
| `GET /api/logistics/settings` | `LogisticsSetting.shop_owner_id` | Logistics settings | `show()` currently uses the same mutation authorization helper. |
| `GET /api/logistics/proofs/{proof}/file` | Proof leg shipment belongs to the shop; safe stored path only | Proof review/detail | Access must not expose another shop's evidence. |
| `GET /api/logistics/attempts/{attempt}/file` | Attempt leg shipment belongs to the shop; safe stored path only | Attempt detail | Current helper is broader for owner and dispatcher evidence. |
| `GET /api/logistics/incidents/{incident}/evidence/{index}` | Incident and leg shipment resolve to the same shop; safe stored path only | Exception review | Current owner/evidence helper bypass is recorded below. |
| `GET /shop-owner/logistics`, `GET /shop-owner/logistics/shipments`, `GET /shop-owner/logistics/riders` | Authenticated shop-owner context | Legacy owner Logistics workspace | Read-only Inertia entry points. |
| `GET /erp/logistics/*` (`dashboard`, `shipments`, `deliveries`, `riders`, `settings`, `batches`) | Resolved ERP actor context and `logistics` module | Employee ERP workspace | `routes/web.php` page shells; API calls still need action policy. |
| `GET /shop-owner/erp/logistics/*` (`dashboard`, `shipments`, `riders`, `batches`, `settings`) | Resolved owner ERP context and `logistics` module | Owner ERP workspace | `routes/shop-owner-erp.php` page shells; page visibility is not mutation authority. |

## Settings and rider administration

| Route/action | Source state | Tenant rule | Owner action | Employee capability | Rider identity / assignment | Maker/checker | Intended UI context |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `PUT /api/logistics/settings` — update settings | Existing settings or a shop default | Settings row belongs to authenticated shop | Configure the enabled Logistics module | `configure-logistics-settings` | Not applicable | Audit the actor and changed setting fields; never log credentials or free text | Settings |
| `POST /api/logistics/riders` — create profile | New profile; linked user, when present, must be same shop | `RiderProfile.shop_owner_id` is the tenant | Manage the shop's rider roster | `manage-logistics-riders` | A profile is not a custody grant until it is linked and assigned | Record creator separately from later proof reviewer | Riders |
| `PATCH /api/logistics/riders/{rider}` — update profile | Existing active/inactive profile | Rider must belong to the shop | Manage roster and availability | `manage-logistics-riders` | Changes to `active`, availability, or link affect future custody checks; do not rewrite old assignments | Record updater; do not make updater a proof reviewer automatically | Riders |

Current broad bypass: `LogisticsSettingController::shop()` and
`RiderProfileController::authorizedShop()` return the authenticated
shop-owner record before checking a capability. Task 9 must replace that
guard-only branch with explicit tenant/module/action decisions.

## Dispatch, assignment, scheduling, and batch work

`batch_manage` below means the existing `manage-logistics-batches` or
`assign-logistics-deliveries` capability for an employee. Where the row names
one of the six enum actions, that enum is the canonical policy key.

| Route/action | Source state | Tenant rule | Owner action | Employee capability | Rider identity / assignment | Maker/checker | Intended UI context |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `POST /api/logistics/batches` — create draft | Candidate legs are pending/eligible and unbatched | Every leg and the new batch belong to the shop | `batch_manage` owner dispatch authority | `batch_manage` | No rider custody yet | Record dispatcher and override reason separately | Batch planner |
| `POST /api/logistics/legs/schedule` — schedule legs | Legs are pending or assigned and not already scheduled | Every leg belongs to the shop | `schedule_delivery` | `assign-logistics-deliveries` or `manage-logistics-batches` | No rider custody yet | Scheduling actor is not a proof reviewer by implication | Batch planner |
| `PUT /api/logistics/batches/{batch}` — replace stops | Draft/editable batch; candidate legs remain eligible | Batch, legs, and shipment tenants match | `batch_manage` owner dispatch authority | `batch_manage` | No reassignment by omission; existing assignment state is service-owned | Record updater | Batch planner |
| `DELETE /api/logistics/batches/{batch}/legs/{leg}` — remove stop | Batch is editable and leg is a member | Batch and leg belong to the shop | `batch_manage` owner dispatch authority | `batch_manage` | No reassignment by omission; existing assignment state is service-owned | Record remover | Batch planner |
| `POST /api/logistics/legs/{leg}/urgent` — mark urgent | Leg is not terminal | Leg shipment belongs to the shop | `batch_manage` owner dispatch authority | `batch_manage` | Does not create custody or assignment | Record dispatcher | Dispatch board |
| `POST /api/logistics/batches/{batch}/offer` — offer batch | Batch is offerable; selected rider is active and same-shop | Batch and rider belong to the shop | `assign_rider` | `assign-logistics-deliveries` or `manage-logistics-batches` | Target rider profile must be authoritative for the selected rider; service owns assignment creation | Offer maker is not proof reviewer | Dispatch board |
| `POST /api/logistics/batches/{batch}/accept` — accept offer | Batch offer is assigned | Batch and assigned rider belong to the same shop | Not an owner dispatch shortcut | Rider account linked to the assigned profile | Exact batch legs/assignment; active profile required | Acceptance actor is not a proof reviewer | Rider dispatch view |
| `POST /api/logistics/batches/{batch}/reject` — reject offer | Batch offer is assigned and rejectable | Batch and assigned rider belong to the same shop | Not an owner dispatch shortcut | Rider account linked to the assigned profile | Exact batch assignment; active profile required | Rejection actor is not a proof reviewer | Rider dispatch view |
| `POST /api/logistics/batches/{batch}/start` — start batch | Batch is accepted and startable | Batch and assigned rider belong to the same shop | Not an owner custody shortcut | Rider account linked to the assigned profile | Exact active assignment and active rider profile; `RiderActiveWorkGuard` remains the concurrency guard | Start actor is not a proof reviewer | Rider dispatch view |
| `POST /api/logistics/batches/{batch}/cancel` — cancel batch | Batch is cancellable | Batch belongs to the shop | `batch_manage` owner dispatch authority | `batch_manage` | Service must preserve/close assignment history | Record cancellation actor | Dispatch board |
| `POST /api/logistics/batches/{batch}/restore` — restore batch | Batch is restorable | Batch belongs to the shop | `batch_manage` owner dispatch authority | `batch_manage` | Service must create a fresh active assignment when required | Record restore actor | Dispatch board |

Current broad bypass: `DeliveryBatchController::dispatcherShop()` returns an
owner tenant without an action decision for batch CRUD, scheduling, urgent,
offer, cancel, restore, and related dispatch routes. Its `assignedRider()`
also checks only the linked user and does not explicitly require an active
rider profile. Task 9 must preserve the service's state/locking checks while
moving responsibility checks to the policy boundary.

## Rider custody progression and exception reporting

The routes in this section are custody-sensitive even when the current
controller calls them “status updates”. The exact leg assignment is part of
the authorization decision; `RiderActiveWorkGuard` remains responsible for
single-active-delivery concurrency and geofence/workflow checks.

| Route/action | Source state | Tenant rule | Owner action | Employee capability | Rider identity / assignment | Maker/checker | Intended UI context |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `POST /api/logistics/legs/{leg}/accept` — accept offer | Assigned offer | Leg shipment and profile belong to the shop | No owner guard shortcut | Assigned rider account | Active linked profile and exact assigned leg | Offer response is not proof review | Rider mobile |
| `POST /api/logistics/legs/{leg}/reject` — reject offer | Assigned offer | Leg shipment and profile belong to the shop | No owner guard shortcut | Assigned rider account | Active linked profile and exact assigned leg | Offer response is not proof review | Rider mobile |
| `POST /api/logistics/legs/{leg}/arrivals` — record arrival | Pickup/in-transit arrival state | Leg shipment belongs to the shop | No owner custody shortcut | `update-logistics-status` only with rider custody | Active linked profile and exact assigned leg | Arrival actor is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/picked-up` — confirm pickup | Assigned/pickup-scheduled/delivery-attempted state | Leg shipment belongs to the shop | No owner custody shortcut | `update-logistics-status` only with rider custody | Active linked profile and exact assigned leg | Pickup actor is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/in-transit` — advance transit | Picked up or retry state | Leg shipment belongs to the shop | No owner custody shortcut | `update-logistics-status` only with rider custody | Active linked profile and exact assigned leg | Transit actor is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/out-for-delivery` — advance route | In-transit state | Leg shipment belongs to the shop | No owner custody shortcut | `update-logistics-status` only with rider custody | Active linked profile and exact assigned leg | Custody actor is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/delivered` — close delivery leg | Proof/workflow-valid delivery state | Leg shipment belongs to the shop | No owner custody shortcut; proof review remains separate | `update-logistics-status` only with rider custody where applicable | Active linked profile and exact assigned leg | Delivery actor is not proof reviewer | Rider mobile / operations |
| `POST /api/logistics/legs/{leg}/proof` — submit proof (`submit_proof`) | Proof-specific service states: pickup before pickup, delivery/receive after pickup and before delivery | Leg shipment belongs to the shop | Only a linked owner-rider profile with the exact assignment | `record-logistics-proof` plus linked rider identity | Active linked profile and exact active assignment are mandatory | Submitter identity must be persisted; submitter cannot review | Rider mobile |
| `POST /api/logistics/legs/{leg}/pickup-proofs/{proof}/confirm` — confirm pickup proof | Pending pickup proof | Proof and leg belong to the shop | No owner custody shortcut | Assigned rider account | Active linked profile and exact assignment | Confirmation actor is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/pickup-proofs/{proof}/reject` — reject pickup proof | Pending pickup proof | Proof and leg belong to the shop | No owner custody shortcut | Assigned rider account | Active linked profile and exact assignment | Rejection actor is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/attempts` — record attempt | Attemptable pickup/delivery state | Leg shipment and assignment belong to the shop | Owner does not become the rider | `update-logistics-status` with the exact assignment | Active linked profile and exact assignment; no generic employee attempt | Attempt recorder is not proof reviewer | Rider mobile |
| `POST /api/logistics/legs/{leg}/report-issue` — report issue | Active custody/incident state | Leg shipment and rider belong to the shop | Owner does not become the reporting rider | `update-logistics-status` with the exact assignment | Active linked profile and exact assignment | Reporter is not exception reviewer automatically | Rider mobile |
| `POST /api/logistics/legs/{leg}/incidents` — create incident | Active rider custody | Leg shipment belongs to the shop | No owner-only incident submission | Rider account with the linked profile | Active linked profile and exact assignment | Reporter is not resolver | Rider mobile |

Current broad bypasses: `ShipmentController::authorizedShop()` and
`authorizeLegUpdate()` allow the shop-owner guard to reach status mutations;
`abortIfUserCannotOperateLeg()` returns immediately for owners. The incident
store path checks only a user-linked profile, and the batch/rider helpers do
not consistently check profile activity. These are explicit migration targets,
not permissions implied by the owner guard.

## Exception resolution, proof review, and return custody

| Route/action | Source state | Tenant rule | Owner action | Employee capability | Rider identity / assignment | Maker/checker | Intended UI context |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `POST /api/logistics/legs/{leg}/cancel` — cancel failed delivery | `delivery_attempted` or `needs_resolution`, subject to custody rules | Leg shipment belongs to the shop | `resolve_exception` | `resolve-logistics-exceptions` or the explicitly assigned dispatch capability, according to the resolution type | Not a rider action; never infer ownership of custody | Record resolver and reason separately | Exception queue |
| `POST /api/logistics/legs/{leg}/resolve/retry` — authorize retry (`resolve_exception`) | `needs_resolution` | Leg shipment belongs to the shop | `resolve_exception` | `resolve-logistics-exceptions` | Assignment service decides whether an active assignment is required for the retry | Resolver is not proof reviewer | Exception queue |
| `POST /api/logistics/legs/{leg}/resolve/return` — require return (`resolve_exception`) | `needs_resolution` | Leg shipment belongs to the shop | `resolve_exception` | `resolve-logistics-exceptions` | Existing custody is preserved by the return service; the resolver is not the return rider | Resolver is not return receipt reviewer | Exception queue |
| `POST /api/logistics/incidents/{incident}/resolve` — resolve incident (`resolve_exception`) | Reported/under-review incident; leg service validates the transition | Incident, leg, and shipment resolve to the same shop | `resolve_exception` | `resolve-logistics-exceptions` | Not a rider custody grant | Resolver identity and resolution evidence are separate from reporter | Exception review |
| `POST /api/logistics/legs/{leg}/return-to-shop` — create return leg (`resolve_exception`) | Failed delivery with `return_required` resolution | Original leg and generated return share the shop | `resolve_exception` | `resolve-logistics-exceptions` | The return assignment is service-owned and must retain exact rider identity | Return creator is not return receipt reviewer | Exception queue |
| `POST /api/logistics/proofs/{proof}/approve` — approve proof (`review_proof`) | `awaiting_proof_approval`, pending delivery proof | Proof leg shipment belongs to the shop | `review_proof` | `approve-proof-of-delivery` or the explicitly assigned dispatcher review capability | No rider custody grant | Maker/checker: submitter and reviewer must differ | Proof review |
| `POST /api/logistics/proofs/{proof}/reject` — reject proof (`review_proof`) | `awaiting_proof_approval`, pending delivery proof | Proof leg shipment belongs to the shop | `review_proof` | `approve-proof-of-delivery` or the explicitly assigned dispatcher review capability | No rider custody grant | Maker/checker: submitter and reviewer must differ; rejection reason is not logged in denial telemetry | Proof review |
| `POST /api/logistics/legs/{leg}/return-proofs/{proof}/handoff` — rider confirms return handoff | Return-to-shop leg after rider custody | Return leg and proof belong to the shop | No owner custody shortcut | Assigned rider account | Active linked profile and exact return-leg assignment | Rider handoff confirmation is the maker step | Rider mobile |
| `POST /api/logistics/legs/{leg}/return-proofs/{proof}/receipt` — confirm return receipt (`confirm_return_receipt`) | Return-to-shop leg; receive proof is `rider_confirmed` | Return leg, proof, and original leg belong to the shop | `confirm_return_receipt` | `approve-proof-of-delivery` or explicit return-receipt capability | No generic rider assignment; the prior rider confirmation is required | Owner/reviewer is checker and must differ from rider handoff actor | Return receipt / exception review |

Current broad bypasses: `ShipmentController::authorizedShopForProofApproval()`
allows the owner guard to approve/reject proofs and confirm return receipts;
`DeliveryIncidentController::resolve()` allows the owner guard to resolve
incidents without an action decision. The policy boundary keeps owner review
authority explicit and keeps proof submission, rider handoff, and return
receipt as separate maker/checker steps.

## Policy decision and denial telemetry contract

`LogisticsActorPolicy` is intentionally side-effect free. It returns only:

```text
allowed: boolean
action: LogisticsAction::value
reason_category: null | lowercase_snake_case_category
```

Examples of non-sensitive denial categories are `cross_shop`,
`module_unavailable`, `action_not_allowed`, `source_state_invalid`,
`rider_identity_required`, `active_assignment_required`,
`maker_checker_identity_missing`, and `maker_checker_conflict`.

Task 9 enforcement boundaries may emit a structured denial event with only:

```text
domain, action, actor_guard, actor_type, shop_id,
denial_category, route_name, correlation_id, request_id
```

The event must not include email addresses, credentials, proof paths or
payloads, customer addresses, phone numbers, uploaded files, notes, rejection
reasons, or other free text. A route/controller should log the category after
the decision and before returning its generic denial response; the pure policy
does not log and cannot accidentally leak request data.

## Migration checklist

Task 9 should migrate the broad owner branches and route families above in
small slices, preserving service-level locking and transition validation. For
each mutation it should:

1. resolve the actor and tenant from the correct guard/context;
2. check the enabled `logistics` module;
3. call the explicit action decision with the actual source record;
4. enforce exact rider identity and assignment for custody routes;
5. record maker/checker identities at the write boundary; and
6. log only the structured denial fields listed above.

The existing `RiderActiveWorkGuard` remains the concurrency/workflow guard;
the action policy is the responsibility and tenant boundary around it.
