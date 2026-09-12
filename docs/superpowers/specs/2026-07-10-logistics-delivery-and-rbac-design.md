# Logistics Delivery and RBAC Design

## Goal

Complete the rider delivery workflow and make every Logistics capability permission-driven while preserving shop tenant isolation.

## Delivery workflow

An assigned rider sees only their assigned shipment legs. They may mark a leg picked up, in transit, and submit delivery proof. Proof submission moves the leg to `awaiting_proof_approval`; it cannot complete a shipment. A Logistics Dispatcher reviews the submitted proof, then either approves it (marking the leg delivered) or rejects it with a reason (returning it to in transit).

## Authorization

All Logistics access uses the existing Spatie `user`-guard permissions, never role names. A shared capability map will define which permissions grant each page/API/action, including `approve-proof-of-delivery`; “dispatcher” is UI wording only. Controllers, request authorization, sidebar visibility, and action buttons consume the same capability rules. Tenant ownership uses the application's established shop-owner relationships/scopes and is checked independently for every shipment, leg, rider, proof, courier provider, and shipping method.

## Permission model

Keep the existing permission names as view/action aliases only: `access-logistics-dashboard`, `view-logistics-shipments`, `assign-logistics-deliveries`, `manage-logistics-riders`, `update-logistics-status`, and `record-logistics-proof`. They never imply destructive capabilities beyond their documented current action. Add only capabilities that have a real endpoint/page: dashboard/view shipments, create/edit/delete shipments, view/manage deliveries, assign/reassign riders, manage riders, manage dispatchers, manage courier providers, manage shipping methods, upload/view/approve proof, update/cancel delivery, and configure logistics settings.

## Permission assignment

The Shop Owner User Access Control and HR employee flows both assign standard `user`-guard Spatie permissions. The audit will remove Logistics-specific business-type filtering or stale permission-cache behavior that blocks valid assignments, and tests will prove direct and role-derived permissions work identically.

## UI

The ERP sidebar exposes Logistics when the user has any Logistics capability, and exposes each subpage/action only for its needed capability. The shipments page becomes the rider work queue when the user has status/proof capability but lacks delivery-management capabilities; proof preview/download is protected by `view-proof-of-delivery`; review controls require `approve-proof-of-delivery`.

## Tests

Feature tests cover direct URLs, API calls, permission assignment through both management paths, direct and role-derived permissions after cache/model refresh, all Logistics page/API/file permissions, rider assignment ownership, proof approval, rejection/re-submission audit history, final-leg completion, and cross-tenant denial.
