Fix Six Cross-Module Bugs Without Changing Existing Workflows

Work only in the current existing worktree. Do not create or switch branches/worktrees.

Audit the current implementation before editing anything. Trace the existing models, routes, controllers, services, policies, notifications, approval records, frontend state, and tests that govern each issue.

The goal is to fix these bugs using the smallest safe changes. Reuse existing services and workflow states. Do not create duplicate approval, numbering, payment, authentication, logistics, repair, payroll, or inventory systems.

Critical constraint: these are targeted bug fixes. Do not redesign or unintentionally change existing business workflows that are already working.

Issues to fix

Order, delivery/shipment, and delivery-proof numbering is globally shared

Audit all human-readable/reference numbers currently displayed for orders, deliveries/shipments, and delivery proofs.

The current behavior appears to use global numbering across all shops, so activity in Shop A affects the next visible number in Shop B.

Fix the numbering so human-readable references are properly scoped to the shop.

Desired behavior:

Shop A
Order #1
Order #2

Shop B
Order #1
Order #2

The same principle applies independently to delivery/shipment references and delivery-proof references.

Do not confuse human-readable numbers with database primary keys. Internal database IDs may remain globally unique.

Audit whether the UI is currently exposing raw IDs such as:

Shipment #14
Delivery #22
Delivery Proof #29

If those are intended business references, provide stable shop-scoped business references instead of relying on raw global primary keys.

Requirements:

Numbering must be scoped by shop_id.
Orders, deliveries/shipments, and delivery proofs must not accidentally share one counter unless the existing domain explicitly requires it.
Generation must be race-safe under concurrent requests.
Use transactions/locking or the project's existing sequence mechanism.
Do not renumber historical records destructively.
Existing URLs/foreign keys must continue using canonical internal IDs.
Existing customer, staff, dispatcher, rider, and shop-owner workflows must continue working.
Audit all modals/pages displaying these references and make their labels consistent.

Warranty repair intake can be received before customer enters courier tracking

This issue applies specifically to the pickup/intake leg of a warranty claim, where the customer arranges the courier to bring the shoes to the shop.

Current bad flow:

Warranty repair
→ Customer-arranged courier pickup/intake
→ Customer has NOT saved tracking information
→ Repairer can still receive the shoes

Correct flow:

Warranty repair
→ Customer-arranged courier intake
→ Customer must first save courier tracking/details
→ Tracking exists
→ Physical shoes arrive
→ Repairer can receive

Enforce this in the backend, not only by disabling a button.

The receive endpoint/service must reject the transition when all of these conditions are true:

repair is a warranty/linked warranty repair;
this is the intake/pickup leg;
method is customer-arranged/third-party courier;
required customer courier tracking has not been saved.

Return a clear validation/business-rule response.

The Repairer UI should clearly indicate why receiving is unavailable, for example:

Waiting for customer courier tracking

Do not apply this requirement to the return-to-owner leg.

Do not break:

Shop-owned repair pickup;
walk-in intake;
customer drop-off;
repair return delivery;
customer-arranged return courier;
normal non-warranty repairs;
existing warranty no-charge behavior.

Use the existing canonical intake/return method fields instead of detecting the method from display labels.

Repair payment success Swal loops indefinitely

This is a Repair payment-only issue. Retail payment does not have the bug and must not be modified unnecessarily.

Current behavior:

Customer pays repair
→ payment succeeds
→ success Swal appears
→ Swal/state repeatedly triggers
→ modal loops indefinitely

Audit:

repair payment success handler;
Inertia redirects;
flash/session messages;
React useEffect dependencies;
query/refetch behavior;
payment state changes;
polling;
duplicate submit handling.

Correct behavior:

Pay repair
→ backend processes payment once
→ frontend receives success once
→ one success Swal
→ user closes/confirms it
→ Swal does not reopen
→ page reflects updated payment state

Protect the backend payment action from accidental duplicate submissions as well.

If the bug is caused by persistent flash state, consume/clear it using the existing project pattern.

If caused by React effects, ensure the effect reacts only to a new success event/reference rather than every render/refetch.

Cover both applicable repair payment paths:

full payment;
remaining-balance payment.

Do not change the Retail payment flow unless shared code contains the actual root cause and the change is proven safe.

Archive Stock does not work in Staff Product/Stock Management

The Staff inventory/product management page currently shows Archive Failed when attempting to archive an item.

Trace the request from:

Staff frontend action;
API/controller;
policy/permission middleware;
inventory service/model;
database constraints;
related variants/sizes/stock movements.

Fix the actual backend cause instead of hiding the error.

Archive should use the project's existing archive/soft-state behavior. Do not hard-delete stock history or financial/procurement references.

Expected behavior:

Staff with authorized inventory permission
→ Archive item
→ backend validates same-shop ownership
→ item becomes archived
→ success feedback
→ item disappears from active list without browser hard refresh
→ available from Archived view if that already exists

Preserve:

historical stock movements;
purchase/stock request references;
POS/order history;
variant history;
tenant/shop boundaries.

Unauthorized staff or cross-shop requests must still be denied.

Payslip awaiting Shop Owner approval does not appear in Approval Center and sends no notification

The HR Payroll page can show employees/payslips in an Awaiting Owner state, but the Shop Owner's Approval Center does not show the pending payslip and the owner receives no notification.

Audit the entire existing payslip approval chain.

Do not create a second approval workflow.

Correct behavior should be:

HR generates/submits payslip
→ payslip reaches Awaiting Owner
→ canonical Shop Owner approval record/queue exists
→ Shop Owner Approval Center shows it under Payslips
→ Shop Owner receives notification
→ owner can inspect details
→ approve/reject through existing workflow
→ approval queue refreshes
→ notification/queue does not duplicate

Ensure:

approval belongs to the correct shop;
only authorized Shop Owner can act;
notification is delivered after the transaction commits;
retries do not create duplicate approval records or notifications;
existing payslip status transitions remain canonical;
payroll generation calculations are not changed;
salary-change workflow is not modified;
existing payslip/history screens remain functional.

Check both the Approval Center data source and the notification recipient query. Do not merely add a frontend card if the backend approval item is missing.

Authenticated Employee or Shop Owner can navigate back to landing/login and log in again

Current behavior:

Employee logged in
→ /erp/time-in
→ manually enters solespace.shop
→ public landing page
→ can access login again

Shop Owner logged in
→ manually enters solespace.shop
→ landing/login available again

Authenticated Employee and Shop Owner sessions should not be allowed to re-enter their own guest/login flow while already authenticated.

Implement this using Laravel middleware/guard-aware redirects rather than frontend-only checks.

Expected behavior:

Authenticated Employee
→ visits /
→ redirect to canonical Employee/ERP authenticated destination

Authenticated Employee
→ visits employee login
→ redirect to canonical Employee/ERP destination

Authenticated Shop Owner
→ visits /
→ redirect to /shop-owner/home or canonical owner home

Authenticated Shop Owner
→ visits /shop-owner/login
→ redirect to owner home

Respect your separate authentication guards and login architecture.

Do not:

auto-login one guard as another;
merge Employee and Shop Owner authentication;
break logout;
break password reset/setup routes;
break MFA/TOTP setup routes;
break customer authentication;
create redirect loops.

Determine the currently authenticated guard/server-side identity before redirecting.

Public landing must remain available for unauthenticated visitors.

Engineering constraints

Before implementation, inspect existing code paths and document the root cause of all six issues. Prefer modifying existing services, policies, sequence generators, approval mechanisms, and middleware over introducing new abstractions.

Any database migration must be minimal and backward-compatible. Do not remove historical data or rewrite existing IDs. If shop-scoped human-readable sequence fields are required, add them safely and provide a deterministic strategy for existing records.

Maintain transaction boundaries and concurrency safety for numbering, payment state, payslip approval creation, and inventory archive actions.

Do not use display strings such as "third party", "Repair Return", or button text as business logic when canonical enums/fields already exist.

Do not solve authorization problems only in React. Sensitive actions must be protected server-side.

Required automated coverage

Add or update tests proving the following:

NUMBERING
✓ Shop A numbering does not affect Shop B
✓ order/delivery/proof counters follow their intended independent scopes
✓ concurrent generation cannot produce duplicate references
✓ old records remain accessible

WARRANTY REPAIR INTAKE
✓ third-party/customer-arranged warranty intake cannot be received without tracking
✓ same flow becomes receivable after tracking is saved
✓ return-to-owner is NOT blocked by this intake rule
✓ shop-owned pickup still works
✓ non-warranty flows remain unchanged

REPAIR PAYMENT
✓ successful repair payment produces one successful transition
✓ duplicate submit is safely rejected/idempotent
✓ frontend success event is consumed once
✓ remaining-balance payment works
✓ retail payment regression remains green

INVENTORY ARCHIVE
✓ authorized same-shop Staff can archive
✓ archived record leaves active list
✓ historical relations remain intact
✓ unauthorized/cross-shop archive is blocked

PAYSLIP APPROVAL
✓ Awaiting Owner produces an Approval Center item
✓ correct Shop Owner receives notification
✓ wrong shop cannot see or approve it
✓ approval/rejection uses canonical state transition
✓ retries do not duplicate queue entries/notifications

AUTH REDIRECT
✓ authenticated Employee cannot reopen employee login
✓ authenticated Employee visiting / is redirected correctly
✓ authenticated Shop Owner cannot reopen owner login
✓ authenticated Shop Owner visiting / is redirected correctly
✓ unauthenticated visitor can still use landing/login
✓ logout returns user to a valid guest state
✓ no redirect loops
UI requirements

Keep the existing SoleSpace visual theme. Do not redesign these pages.

Use the existing monochrome/neutral component system, existing modal/SweetAlert styling, buttons, tables, badges, spacing, and typography.

Do not introduce colorful AI-generated-looking cards, modals, alerts, or unrelated components.

Where frontend data changes after a successful action, update/refetch the relevant local/Inertia data automatically. Do not require a full browser refresh.

Final deliverable

After the fixes, provide a concise implementation report containing:

Root cause for each issue
Files changed
Database migration(s), if any
Behavior before vs after
Authorization/security changes
Tests added/updated
Commands/tests executed
Any remaining risks or intentionally unchanged workflows

Do not commit or push unless explicitly asked.

Most importantly: fix these six defects without altering unrelated Retail, Repair, Payroll, Logistics, Inventory, Shop Owner, Customer, or authentication workflows.