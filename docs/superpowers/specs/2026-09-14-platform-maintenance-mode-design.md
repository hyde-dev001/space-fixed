# SoleSpace Platform Maintenance Mode Design Specification

**Date:** September 14, 2026
**Status:** Consolidated for review
**Scope:** Core V1 global application-level maintenance
**Platform:** Laravel 12, Inertia 2, React 18, TypeScript, Vite, Tailwind CSS

---

## 1. Purpose

SoleSpace needs one global maintenance control that can warn users, prevent new critical operations shortly before downtime, block normal application access during downtime, preserve privileged recovery access, and restore users safely afterward.

The system must be controlled from the existing Admin Module and apply to public, Customer, Shop Owner, ERP employee, Logistics, Rider, and other normal platform areas. Admin and Super Admin must retain their existing Admin Module access during maintenance.

This is an application-level maintenance system. Laravel's `php artisan down` is not the primary mechanism because it cannot provide the required lifecycle, warnings, transaction freeze, audit history, and role-aware recovery behavior.

## 2. Scope Decisions

### Core V1 includes

- One global effective maintenance window at a time; multiple future windows may be scheduled when their intervals do not overlap.
- Scheduled and emergency maintenance.
- Server-authoritative effective state.
- Advance banner, server-synchronized countdown, and one-time warning modal.
- Opt-in dirty-form warnings for participating forms.
- An explicit allowlist of critical operation initiations that freeze before maintenance.
- Centralized server-side enforcement.
- A safe public maintenance status endpoint.
- A branded maintenance and recovery experience.
- Existing-session preservation.
- Admin read access and Super Admin lifecycle controls.
- Progress stages and public status updates.
- Maintenance history, privileged audit events, concurrency protection, and failure-safe behavior.

### Deferred from Core V1

- SoleSpace Shoe Catch, local scores, audio, and game controls. These belong to a separate Phase 2 specification.
- Automatic browser draft storage or draft restoration.
- Per-shop, per-module, or per-role maintenance.
- A platform-wide read-only mode.
- WebSocket or Reverb status delivery.
- Email or SMS maintenance campaigns.
- Online leaderboards, rewards, vouchers, or acknowledgment analytics.

## 3. Design Principles

1. **Canonical timestamps authorize.** Effective state is derived on the server from terminal state and UTC timestamps.
2. **Middleware enforces.** Client state never grants access or authorizes a write.
3. **The scheduler reconciles and records; it does not authorize.** Delayed scheduler execution cannot delay activation or restoration.
4. **The frontend explains.** Banners, countdowns, disabled buttons, and maintenance screens improve UX but are not security controls.
5. **Accepted work is not interrupted.** Maintenance enforcement applies when a request enters the application, not midway through an executing request or transaction.
6. **Rejected writes are never replayed automatically.** Recovery only navigates to a safe canonical GET destination.
7. **Reuse existing platform systems.** Use the existing Admin shell, capabilities, recent reauthentication, privileged audit log, Inertia provider boundary, Axios setup, database transaction patterns, and cache facilities.

## 4. Architecture

The selected design uses a database-backed maintenance window, a small server-side state resolver, centralized HTTP middleware, and one shared frontend provider.

```text
Admin Maintenance Controls
        |
        v
maintenance_windows (canonical history and timing)
        |
        +--> Maintenance state resolver
        |       +--> cache optimization
        |       +--> database fallback
        |
        +--> centralized request enforcement
        |       +--> active maintenance response
        |       +--> pre-maintenance freeze response
        |       +--> explicit named-route bypasses
        |
        +--> scheduler reconciliation and audit
        |
        +--> shared MaintenanceProvider
                +--> banner and countdown
                +--> warning modal
                +--> freeze awareness
                +--> single-flight maintenance transition
                +--> maintenance/recovery page
```

The database is the source of truth. Cache reduces repeated reads but does not own state. The frontend consumes a sanitized status projection and cannot supply maintenance state back to the server.

## 5. Persisted and Effective State

### Persisted lifecycle

```text
draft -> scheduled -> active -> ended
                    \
scheduled ----------> cancelled

emergency create -> active -> ended
```

`ended` and `cancelled` are terminal. Terminal records are immutable history.

### Effective-state rules

Every read and lifecycle command must validate the **effective state**, not only the persisted `status`.

For a nonterminal scheduled or active record:

```text
now < starts_at                 => Scheduled
starts_at <= now < ends_at      => Active
now >= ends_at                  => Ended
```

Terminal persisted states override time:

```text
status = cancelled              => Cancelled
status = ended                  => Ended
```

A draft remains Draft and is never enforced or publicly announced.

Consequences:

- A persisted `scheduled` record is already effectively Active once `starts_at` arrives.
- A persisted `active` record is already effectively Ended once `ends_at` passes.
- The scheduler may later persist those transitions and their audit events, but access never waits for it.
- Edit, cancel, activate, extend, publish, and end commands all use effective state.

### Time interval and overlap rule

Maintenance windows use half-open intervals:

```text
[starts_at, ends_at)
```

Adjacent windows are valid:

```text
10:00-11:00
11:00-12:00
```

Drafts may overlap because they are private and have no enforcement effect. Overlap validation becomes mandatory when a window is scheduled, activated, edited while Scheduled, or extended. Only Scheduled and effectively Active windows participate in overlap conflicts. Each applicable command re-checks overlap while holding the lifecycle lock and database transaction. Extending an active window into a future scheduled window returns `409 Conflict`.

All timestamps are persisted in UTC and presented in `Asia/Manila`. Emergency maintenance requires an expected `ends_at`; it is displayed as an estimate, not a guarantee, and can be extended.

## 6. Lifecycle Commands

| Command | Allowed effective state | Result |
|---|---|---|
| Create draft | No conflicting command in progress | Internal Draft |
| Schedule draft | Draft | Scheduled after validation and overlap check |
| Edit | Draft, or Scheduled before activation | Updates content/timing and re-checks overlap |
| Cancel | Scheduled | Cancelled terminal state |
| Start now / activate | Scheduled, or emergency creation | Sets `starts_at = now`, `activated_at = now`, and `status = active` after overlap check |
| Extend | Active | Updates estimated end after future-conflict check |
| Update progress | Active | Changes the public progress stage |
| Publish public update | Scheduled or Active | Changes the sanitized public update |
| End now | Active | Ended terminal state and immediate restoration |

Public messages and progress cannot be changed after effective Ended or Cancelled. Internal administrative annotations after termination are outside Core V1.

Scheduling requires `starts_at` to be in the future according to server time. Immediate activation uses Start Now, which replaces a future scheduled start with the command time.

Repeated activation of the same already-active window and repeated ending of the same already-ended window are idempotent no-ops. They must not create duplicate audit events. A stale version, invalid effective state, or conflicting window returns `409 Conflict`; authorization failures return `403`; malformed input returns `422`.

## 7. Concurrency and Atomicity

All lifecycle mutations serialize through one database-backed advisory lock with the fixed key `platform_maintenance_command`, then validate effective state, version, and overlap again inside a database transaction. On the deployed MySQL database, this is a named advisory lock acquired through the same canonical database connection before the transaction and released in a `finally` path. Correctness must not depend on Redis or Laravel cache locking.

Once the advisory lock is held, the transaction:

1. Lock the target and relevant Scheduled/effectively Active maintenance records with `lockForUpdate`.
2. Resolve effective state using server time.
3. Validate the submitted version token.
4. Validate the requested transition and overlap.
5. Persist the lifecycle change.
6. Record the matching privileged audit event.
7. Commit.

The database advisory lock also serializes first-window creation, when no maintenance row exists to lock. Cache invalidation occurs only after commit. If the lifecycle lock cannot be acquired within its bounded timeout, the command fails safely. If the audit write fails, the lifecycle transaction rolls back. No partially applied maintenance command is reported as successful.

## 8. Data Model

Create one additive `maintenance_windows` table.

### Content

- `title`
- `public_message`
- `internal_note`

### Lifecycle and timing

- `status`: `draft`, `scheduled`, `active`, `ended`, or `cancelled`
- `starts_at`
- `ends_at`
- `activated_at`
- `ended_at`
- `cancelled_at`
- `version`

### Warning and public status

- `notify_before_minutes`
- `transaction_freeze_minutes`, nullable to disable the freeze
- `progress_stage`, nullable
- `public_update_message`, nullable
- `public_update_updated_at`, nullable

Super Admin may select these progress stages only while the window is effectively Active:

```text
Maintenance Starting
Maintenance in Progress
Final Checks
```

`Service Restored` is derived automatically when effective state becomes Ended or Operational; it is not an editable post-termination progress value. The UI must not display invented completion percentages.

### Actors

- `created_by`
- `updated_by`
- `activated_by`
- `ended_by`
- `cancelled_by`

Actor columns are nullable foreign keys to the existing `super_admins` table and use null-on-delete so the maintenance record survives account removal. Historical actor attribution is preserved by the existing privileged audit event's actor snapshot properties even if the related privileged account is later hard-deleted.

### Storage rules

- UTC timestamps.
- Indexes supporting lifecycle and time resolution.
- No soft deletes.
- No second maintenance ledger or audit table.
- `starts_at < ends_at`.
- Freeze duration is null/disabled or no greater than the notification threshold.
- Drafts may overlap; Scheduled and effectively Active intervals may not overlap.
- Terminal records remain immutable.

## 9. Authorization and Admin Access

Reuse the existing `super_admin` guard, fixed capabilities, active-account middleware, MFA rules, and privileged route conventions.

Add two capabilities:

| Capability | Assignment | Purpose |
|---|---|---|
| `view_platform_maintenance` | Admin and Super Admin | View current state, upcoming window, details, and history |
| `manage_platform_maintenance` | Super Admin only | Schedule, edit, cancel, activate, extend, update, and end maintenance |

The frontend hides controls based on capability, but every mutation repeats authorization on the backend. Start Now and End Now also use the existing recent-reauthentication protection and explicit confirmation UI.

During maintenance, both Admin and Super Admin retain their full existing Admin Module access. Maintenance does not weaken any existing authorization, MFA, active-account, tenant, or session rule.

## 10. Admin Experience

Add `System Maintenance` to the existing Admin navigation at:

```text
/admin/maintenance
```

The page uses the existing SoleSpace Admin shell and visual conventions. It contains:

- Current platform status: Operational, Scheduled Maintenance, or Maintenance Active.
- Upcoming maintenance details.
- Schedule/edit form for authorized Super Admins.
- Start Maintenance Now with confirmation and required expected end time.
- Active controls: extend ETA, update progress, publish a public update, and end maintenance.
- Maintenance history and read-only details.

Admin users see the same status and history without mutation controls. High-impact actions remain Super Admin-only.

Default configuration values are:

```text
Notify users before: 15 minutes
Critical transaction freeze: 3 minutes before
```

The schedule form supports 5, 10, 15, 30, and 60 minute warning choices and an optional freeze of 1, 2, 3, or 5 minutes. Validation, not the UI alone, enforces coherent timing.

## 11. Public Status Contract

Provide:

```text
GET /system/maintenance-status
```

The endpoint returns only the current safe projection:

```json
{
  "id": 42,
  "state": "scheduled",
  "title": "Scheduled Platform Maintenance",
  "message": "SoleSpace will undergo scheduled maintenance.",
  "progress_stage": null,
  "update_message": null,
  "update_message_updated_at": null,
  "starts_at": "2026-09-14T15:00:00Z",
  "ends_at": "2026-09-14T15:30:00Z",
  "notify_before_minutes": 15,
  "transaction_freeze_minutes": 3,
  "server_time": "2026-09-14T14:45:00Z"
}
```

Public selection is deterministic:

```text
Effectively Active window
    -> return Active
else nearest Scheduled window whose warning threshold has begun
    -> return Scheduled
else
    -> return Operational
```

This means future maintenance remains private until its notification threshold begins. If no window is publicly relevant, the response reports `operational` and omits window-only data according to the final response-resource convention. The internal enforcement snapshot may still retain the nearest future Scheduled window so it can calculate upcoming freeze and activation boundaries.

The endpoint never exposes internal notes, actor IDs, audit properties, deployment details, security configuration, or secrets. It uses `Cache-Control: no-store` so clients do not mistake a stale HTTP cache for current service state.

## 12. Request Enforcement

One centralized maintenance middleware makes the request-entry decision in this order:

1. Resolve the canonical named route.
2. Apply an explicit bypass, if any.
3. Resolve effective maintenance state from cache with database fallback.
4. If effectively Active, return the maintenance response.
5. If in the pre-maintenance freeze and the route is an explicitly frozen initiation, return the freeze response.
6. Otherwise continue normally.

### Active maintenance responses

- Protected browser navigation: HTTP `503 Service Unavailable` with the maintenance experience.
- API/JSON/write request: HTTP `503` with code `MAINTENANCE_ACTIVE` and safe maintenance metadata.
- `/maintenance` itself is an explicitly allowed canonical GET page and can render successfully while protected application routes return 503.
- Include `Retry-After` when a useful estimate is available.

### Explicit bypasses

Core V1 permits only named, reviewed exceptions:

- Existing `admin.*` Admin Module routes.
- Existing legacy `superAdmin.*` privileged routes while they remain in use.
- Admin authentication, setup, reset, MFA, recent reauthentication, and logout routes.
- `/maintenance`.
- `/system/maintenance-status`.
- `/up`.
- The existing PayMongo webhook after assigning it a canonical route name.
- A future courier or server callback only after it is registered as an explicit named route and its existing authentication/signature controls are verified.

There is no blanket `/api/*` exemption. Static assets remain served by the web server. Scheduler and queue processes do not pass through HTTP maintenance middleware.

The PayMongo webhook bypass changes only maintenance routing; existing signature verification remains mandatory.

## 13. Critical Transaction Freeze

The freeze is a short pre-maintenance guard for **new critical operation initiation**, not a platform-wide write ban.

The server owns an explicit set of exact named routes for the current canonical initiation endpoints in these families:

- Checkout creation.
- Customer payment initiation.
- Refund initiation.
- POS transaction creation.
- Payroll run initiation.
- Dispatch creation.
- Procurement submission.
- Approval decisions.

The implementation plan must inventory and name the actual route entries in each family before coding. Prefix matching, descriptions, frontend labels, and client-supplied operation types are not acceptable classifiers.

During the configured freeze:

- A listed new initiation returns HTTP `409` with code `MAINTENANCE_FREEZE_ACTIVE` and safe timing metadata.
- Existing accepted work and requests already executing continue.
- Provider callbacks, payment webhooks, queue jobs, and completion/reconciliation paths remain allowed according to their normal security rules.
- The middleware never interrupts an open database transaction.

## 14. Shared Frontend State

Add one `MaintenanceProvider` at the existing root `ApplicationProviders` boundary. Do not duplicate polling in Customer, Shop Owner, ERP, Rider, or public pages.

The provider:

- Fetches the safe status endpoint approximately every 30 seconds.
- Rechecks after Inertia navigation.
- Rechecks on `visibilitychange` when the tab becomes visible.
- Stores the latest successful server projection.
- Calculates `server_time - client_time` once per successful response and uses that offset for the visible local countdown.
- Re-synchronizes the offset on every poll, navigation refresh, and visibility refresh.

The countdown never trusts the device clock directly.

## 15. Warning and Dirty-Form UX

When the notification threshold is reached, show a persistent banner containing the title, safe public message, expected window, and a server-synchronized countdown.

At approximately five minutes before the start, show one warning modal per maintenance-window ID. The acknowledgment key may be kept in local storage because it contains no sensitive business data. The banner remains visible after acknowledgment.

Participating long-form screens may opt into the existing dirty-state pattern and warn users that they have unsaved changes as maintenance approaches. Core V1 does not persist form contents or restore drafts. Passwords, payment data, authentication secrets, TOTP secrets, and sensitive financial information must never be copied into browser storage by this feature.

During the freeze, known critical buttons become unavailable with a clear reason. This is only a UX aid; the server remains authoritative.

## 16. Maintenance and Recovery Experience

When maintenance becomes active, the shared Axios/Inertia error handling recognizes the canonical maintenance code and performs at most one transition to the safe GET `/maintenance`. Concurrent failed requests must not cause repeated navigation or duplicate toast messages.

The maintenance page presents, in this order:

1. SoleSpace branding.
2. Maintenance title and public message.
3. Current progress stage.
4. Expected completion and server-synchronized remaining time.
5. Latest public update and update timestamp.
6. `Check Again`.

The page polls on the shared cadence. `Check Again` requests current status immediately but does not bypass the server or assume success.

If polling fails, retain the last known state and display that status could not be refreshed. A failed status request never means service is restored.

When effective state becomes Ended or Operational, transition the page to a screen-reader-discoverable **Service Restored** state and show a **Safe Return** button. Do not force an automatic redirect.

Safe Return always uses canonical GET destinations:

| Actor | Destination rule |
|---|---|
| Customer | `route('landing')` (`/`), the current canonical post-login customer destination |
| Shop Owner | `/shop-owner/home` |
| Employee | Canonical role/ERP landing page |
| Rider | Canonical rider home |
| Guest | `route('landing')` (`/`) |

Never use an arbitrary previous URL and never replay an interrupted POST, checkout, approval, payment, refund, payroll, dispatch, or procurement request.

Maintenance does not force logout. Existing sessions remain valid unless they expire through normal authentication behavior.

### Accessibility

- The warning banner and state changes use an appropriate status/live-region pattern.
- Service Restored is announced when the state changes.
- The countdown is visible but is not announced every second.
- Buttons and modal controls remain keyboard accessible and preserve existing focus conventions.

## 17. Scheduler and Cache

The scheduler periodically reconciles persisted state with effective state:

- Persist `scheduled -> active` after `starts_at` if not already recorded, with `activated_at = starts_at`.
- Persist nonterminal `-> ended` after `ends_at` if not already recorded, with `ended_at = ends_at`.
- Record each real transition once.

Automatic transitions use a system actor, never a fake Super Admin. Their actor foreign key remains null, and the existing non-human/console privileged-audit path records an explicit maintenance-scheduler source plus a correlation ID. The transition timestamps represent when the platform state became effective, not when a delayed scheduler process happened to run.

This does not authorize access. Middleware derives effective state independently on every decision.

The internal cached snapshot includes:

```text
resolved_at
next_transition_at
expires_at
maintenance window ID and version
the timing needed to derive effective state
```

`next_transition_at` is the earliest known decision boundary after resolution: warning start, freeze start, maintenance start, or maintenance end. `expires_at` is the earlier of a 30-second maximum cache TTL and `next_transition_at`. A snapshot is usable only while server time is strictly before `expires_at`; it must never authorize across its next known transition.

Read behavior is:

```text
usable cache -> return projection
cache unavailable/miss -> database resolution
```

Successful lifecycle commits invalidate or refresh cached state after commit. If cache is unavailable, enforcement continues from the database.

If database resolution fails, an unexpired Scheduled or Active snapshot may continue to enforce the same or a more restrictive result until its next known boundary. A cached Operational snapshot must not authorize normal traffic while the canonical database is unavailable. If both canonical database resolution and a usable restrictive snapshot are unavailable, normal platform HTTP traffic fails closed with a generic 503. Explicit privileged and infrastructure bypass routes remain reachable, although their own dependencies may independently fail. The public status request reports unavailable rather than restored.

## 18. Audit and Observability

Reuse the existing `PrivilegedAudit` service and `activity('privileged')` log. Do not create a maintenance-specific audit table.

Use the repository's snake-case event convention:

- `platform_maintenance_created`
- `platform_maintenance_scheduled`
- `platform_maintenance_updated`
- `platform_maintenance_cancelled`
- `platform_maintenance_activated`
- `platform_maintenance_extended`
- `platform_maintenance_progress_updated`
- `platform_maintenance_public_update_changed`
- `platform_maintenance_ended`

Human-initiated events record the actor, target window, correlation ID, IP, safe previous/new lifecycle values, and the existing permitted actor snapshot fields. Scheduler events have no privileged-account causer and identify the maintenance scheduler as the system source. Do not duplicate secrets or unrestricted internal-note content in audit properties.

Operational logs are reserved for meaningful failures: state-resolution failures, lifecycle command failures/conflicts, and scheduler reconciliation lag. Do not write one application log entry for every blocked request. Use existing monitoring or aggregate counters grouped by response code and route family where available; do not add a new observability platform solely for this feature.

## 19. Failure Behavior

| Failure | Required behavior |
|---|---|
| Cache unavailable | Resolve from database |
| Scheduler delayed | Timestamps still enforce and restore access |
| Status polling unavailable | Keep last known state; do not show restored |
| Database and usable server snapshot unavailable | Generic fail-closed 503 for normal traffic |
| Lifecycle lock unavailable | Reject command safely; no partial write |
| Audit persistence fails | Roll back lifecycle mutation |
| Concurrent active-mode client errors | One transition to `/maintenance` |
| Duplicate lifecycle command | Idempotent no-op where already at target state |
| Stale version or overlap | Controlled 409 response |
| Unauthorized or malformed command | Controlled 403 or 422 response |

## 20. Security Requirements

- Normal users cannot view internal maintenance data or mutate maintenance state.
- Super Admin mutation authorization is enforced server-side.
- Admin and Super Admin bypass does not bypass their existing authentication, MFA, active-account, capability, or session checks.
- The public endpoint exposes only its documented safe projection.
- Critical-operation classification is server-owned and based on exact named routes.
- Maintenance response handling never replays a rejected write.
- External callback bypasses remain narrow and retain signature/authentication verification.
- Lifecycle mutation, audit, and cache invalidation follow committed-state ordering.
- Stale-write and concurrency protections remain active.
- Maintenance controls do not accept frontend-supplied effective state or actor identity.

## 21. Verification Strategy

### Backend tests

- Effective-state boundaries before start, exactly at start, before end, and exactly at end.
- Terminal-state override.
- Every lifecycle command validates effective rather than persisted state.
- Drafts may overlap, while Scheduled/effectively Active windows may not.
- Adjacent half-open windows are accepted.
- Overlapping windows and conflicting extensions are rejected.
- Start Now sets `starts_at`, `activated_at`, and persisted Active state to the same server command time.
- Emergency maintenance requires an expected end.
- Ended and Cancelled records are immutable.
- Public updates are allowed only while effectively Scheduled or Active.
- Stale versions, the database advisory lock, concurrent first-window creation/activation, and idempotent repeat commands.
- Admin view and Super Admin mutation capability enforcement.
- Active response and bypass matrix for browser/API requests.
- Every exact critical initiation route is frozen while completion/callback routes continue.
- PayMongo webhook remains reachable and signature protected.
- Public status deterministically selects Active, otherwise the nearest warned Scheduled window, otherwise Operational.
- Cache failure falls back to database; expired snapshots and Operational snapshots cannot fail open.
- Snapshot expiry never crosses warning, freeze, start, or end boundaries.
- Scheduler delay does not alter effective enforcement; automatic timestamps use the canonical boundary and the audit uses a system source.
- Public status response is sanitized and not HTTP-cached.
- Controlled 403, 409, 422, and 503 contracts.

### Frontend tests

- One provider at the existing application boundary.
- Server-time offset drives countdown and resynchronizes on all triggers.
- Banner persists and five-minute modal appears once per window.
- Dirty-form warning is opt-in and stores no draft payload.
- Freeze state disables known critical controls with a reason.
- Concurrent maintenance errors cause one navigation.
- Rejected writes are not replayed.
- Polling failure retains the last known state.
- Service Restored and Safe Return use canonical GET destinations.
- No forced logout or automatic recovery redirect.
- Accessible status announcements do not announce every countdown second.

### Browser QA matrix

Verify scheduled, emergency, freeze, active, extension, manual end, automatic end, polling failure, cache failure, scheduler delay, and recovery flows on desktop and narrow viewports for:

- Guest and Customer.
- Individual and Company Shop Owner.
- Manager and representative ERP roles, including Finance, HR, Cashier, Repair, Inventory, Procurement, and Logistics.
- Rider.
- Admin.
- Super Admin.

Specific browser checks include an idle tab becoming visible, navigation during activation, API failure during activation, a payment accepted before freeze, PayMongo callback during maintenance, one-time modal behavior, Check Again, preserved session, and actor-specific Safe Return.

## 22. Acceptance Criteria

Core V1 is ready for implementation completion when all of the following are true:

- Super Admin can schedule, edit, cancel, activate, extend, update, and end a global maintenance window according to effective-state rules.
- Admin can view maintenance state and history but cannot mutate it.
- All lifecycle commands are atomic, stale-safe, overlap-safe, and audited.
- Drafts may overlap; only Scheduled and effectively Active windows participate in conflicts.
- Adjacent `[start, end)` windows are allowed; actual overlaps are rejected.
- Start Now atomically changes the start and activation timestamps to server now.
- Emergency maintenance requires an estimated end time.
- Server timestamps enforce activation and restoration without waiting for the scheduler.
- Users receive a server-synchronized advance warning and a one-time warning modal.
- Participating forms can warn about unsaved work without storing drafts.
- Only explicitly allowlisted new critical operations freeze before maintenance.
- Requests already accepted by the server and secure external callbacks can complete.
- Normal protected traffic receives the documented maintenance response while the Admin Module and explicit infrastructure routes remain available.
- Maintenance status uses the deterministic Active/warned-Scheduled/Operational selection rule and remains free of internal information.
- Cache snapshots expire before their next known decision boundary, and an Operational snapshot cannot fail open during database failure.
- Active users transition once to the maintenance page without forced logout or duplicated errors.
- Status-fetch failure is never interpreted as restoration.
- Recovery shows Service Restored and requires a canonical Safe Return GET action.
- No rejected write is replayed.
- Automatic scheduler transitions use a system source and canonical transition timestamps, never a fake administrator or delayed execution time.
- Shoe Catch and all other Phase 2 entertainment features remain outside Core V1.

## 23. Final Flow

### Planned maintenance

```text
Super Admin schedules one non-overlapping window
        -> advance warning and server-synced countdown
        -> one-time five-minute warning
        -> explicit critical-operation freeze
        -> effective start timestamp arrives
        -> middleware blocks normal protected traffic
        -> maintenance page shows status, ETA, and updates
        -> effective end arrives or Super Admin ends early
        -> Service Restored appears
        -> user chooses canonical Safe Return
```

### Emergency maintenance

```text
Super Admin enters estimated end time
        -> recent reauthentication and confirmation
        -> atomic activation and audit
        -> middleware immediately blocks normal protected traffic
        -> Super Admin may extend, update progress/message, or end
        -> Service Restored appears
        -> user chooses canonical Safe Return
```

## 24. Frozen Design Boundary

Core V1 has four separate responsibilities:

```text
Lifecycle state and audit
        != request enforcement
        != user notification and recovery
        != Phase 2 entertainment
```

A failure in one layer must not allow another layer to invent or weaken maintenance state. The concise governing rule is:

> Canonical timestamps authorize, middleware enforces, the scheduler reconciles, and the frontend explains.
