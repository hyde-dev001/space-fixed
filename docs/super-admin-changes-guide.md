# Super Admin Changes and Operations Guide

This guide explains the Super Admin module after the Phase 0–8 hardening program. It is intended for platform operators, Admin/Super Admin users, support staff, QA, and developers who need to understand the final behavior without reading every implementation plan.

For deployment and incident procedures, use the linked runbooks rather than this overview.

## Current delivery status

The application implementation is complete in the Super Admin worktree through commit `9f92ebf44`.

The final status is:

```text
IMPLEMENTATION COMPLETE — PRODUCTION EVIDENCE PENDING
```

Local backend, frontend, route, schedule, build, and unauthenticated browser checks passed. Production readiness still requires deployment-owner evidence for scheduler/queue/cache operation, MariaDB/MySQL query plans and row-lock behavior, deployed compatibility-link telemetry, and authenticated browser verification for both privileged roles.

Do not describe the module as `EXECUTED / PRODUCTION-READY` until those items are verified or explicitly marked not applicable for the deployment architecture.

## What changed at a glance

The old Super Admin module was hardened incrementally rather than rewritten.

| Area | Final behavior |
| --- | --- |
| Authorization | Backend-enforced fixed capabilities for `admin` and `super_admin`; UI visibility is not treated as authorization. |
| Authentication | Separate `super_admin` guard, account-state checks, mandatory MFA, recovery codes, registered privileged sessions, and recent reauthentication for high-risk actions. |
| First account | Created with the interactive `super-admin:bootstrap` command as `pending_setup`; the operator creates the password and enrolls MFA through a one-time setup flow. |
| Administrator management | Invitation-based setup, role/lifecycle controls, MFA reset, session invalidation, self-protection, and final-active-Super-Admin protection. |
| Registrations | Explicit pending/approved/rejected transitions with transactional document verification, audit, and delivery behavior. |
| Users and shops | Suspend/reactivate and archive/restore are separate, reversible workflows; routine permanent deletion is unavailable. |
| Reports and appeals | Guarded moderation state machines, suspension provenance, one current appeal per suspension, stale-state protection, and Super-Admin-only appeal decisions. |
| Sensitive documents | Private storage and scoped streaming; access fails closed when its mandatory audit cannot be recorded. |
| Business documents | Immutable versions, DTI/SEC distinction, reviewer-verified expiry metadata, renewal review, legacy continuity, and fixed 30/7/0-day reminders. |
| Billing | Authoritative payment/refund history, guarded cancellation, narrow legacy correction, provider-backed refunds, and bounded detail-on-demand history. |
| Audit and notifications | `PrivilegedAudit` is authoritative history; notifications are dismissible operational messages and never replace audit evidence. |
| Routes and architecture | `/admin` is the canonical privileged mutation surface; focused controllers/services replaced duplicate monolithic ownership. |
| Scale | Operational lists are server-paginated, filters validated, metrics aggregated in the database, and relation hydration bounded. |

## Roles and capabilities

Both roles authenticate through the same privileged guard and must complete MFA. Their authority differs through fixed capabilities mapped in code.

| Capability | Admin | Super Admin |
| --- | :---: | :---: |
| View operational monitoring | Yes | Yes |
| Review registrations and private registration documents | Yes | Yes |
| Approve/reject registrations and document renewals | Yes | Yes |
| View and intervene in authorized user/shop accounts | Yes | Yes |
| Moderate shop reports and flagged accounts | Yes | Yes |
| View suspension appeals | Yes | Yes |
| Resolve suspension appeals | No | Yes |
| Manage administrator accounts and roles | No | Yes |
| Manage premium plans | No | Yes |
| Perform exceptional subscription interventions | No | Yes |
| View privileged audit | Own/authorized operational scope | Full privileged history |
| Manage own password, MFA, and recovery codes | Yes | Yes |
| Reset another administrator's MFA/platform security | No | Yes |

There is no configurable permission-management UI. Capabilities are deterministic code mappings. Backend middleware is authoritative even when the frontend hides an unavailable action.

## Signing in and account security

### Normal sign-in

1. Open `/admin/login`.
2. Enter the privileged account email and password.
3. Complete the TOTP challenge. A saved one-time recovery code may be used for account recovery where supported.
4. The application registers the completed privileged session and security version.
5. The account must remain `active` and MFA-complete on subsequent requests.

Suspending an administrator or changing their security version removes authority on the next request even if physical session cleanup is delayed.

### Recent reauthentication

High-risk actions redirect to `/admin/reauthenticate` when the current grant is missing or expired. Reauthentication requires the current password and a fresh TOTP challenge and is valid for 15 minutes by default.

After success, deliberately retry the original action. The application does not automatically replay a mutation.

Actions requiring recent reauthentication include:

- administrator invitation, lifecycle, role, and MFA-reset actions;
- own password, MFA reset, and recovery-code regeneration;
- user/shop archive and restore;
- subscription cancellation, legacy correction, and refund.

### Recovery codes

- Recovery codes are shown once and must be saved before acknowledgement.
- Regeneration replaces the prior set and requires recent reauthentication.
- Plaintext codes cannot be retrieved later.
- A recovery code is primarily an account-recovery credential; it does not replace the TOTP requirement for routine recent reauthentication.

## Initial Super Admin bootstrap

Use this only when no privileged account has completed platform bootstrap:

```powershell
php artisan super-admin:bootstrap
```

The command is interactive and creates one `pending_setup` Super Admin plus a one-time setup invitation. It does not accept password, TOTP secret, or recovery codes as command arguments.

The first operator then:

```text
opens the HTTPS setup link
→ exchanges the fragment token
→ creates their password
→ enrolls TOTP
→ saves and acknowledges recovery codes
→ becomes active and MFA-complete
```

A second bootstrap attempt is refused. After bootstrap, the system protects the final active, MFA-enrolled Super Admin across suspension, deactivation, role demotion, and MFA-reset/setup-required transitions.

See [Phase 1 identity and MFA runbook](runbooks/super-admin-phase-1-identity-mfa.md) for rollout and recovery procedures.

## Managing administrators

Canonical page: `/admin/administrators` (Super Admin only).

Administrator transitions are deliberately state-specific:

| Current state | Supported next state |
| --- | --- |
| `pending_setup` | `active` after password creation, TOTP enrollment, and recovery-code acknowledgement |
| `active` | `suspended` or `inactive` |
| `suspended` | `active` or `inactive` |
| `inactive` | `pending_setup` through activation and a fresh setup flow |

Operational rules:

- New administrators are invited; they are not given a server-generated reusable password.
- A pending setup invitation may be resent; the old setup token is replaced.
- Activating an inactive administrator returns them to `pending_setup` with fresh setup/MFA enrollment.
- Administrator accounts are not routinely archived or deleted. Historical identity is retained.
- An operator cannot suspend, deactivate, change the role of, or reset MFA for their own account through management endpoints.
- An operation cannot eliminate the final active MFA-enrolled Super Admin.
- Role changes are limited to `admin` and `super_admin`.

## Registration review

Canonical page: `/admin/registrations`.

The queue uses server-side search/status filters and pagination. Opening documents uses scoped private routes; no raw storage path or public `/storage` URL is exposed.

Approval flow:

```text
pending registration
→ reviewer opens private evidence
→ reviewer verifies document type and validity metadata
→ transaction locks the registration/documents
→ one approval decision commits
→ authoritative audit commits with the local transition
→ operational delivery is handed off after commit
```

Retries with the same already-applied decision are idempotent where defined. A stale or conflicting decision returns a conflict instead of overwriting newer state.

The canonical registration flow is the only current shop/document submission path. Legacy registration POST endpoints were removed.

## Business documents and renewals

Canonical renewal queue: `/admin/document-renewals`.

### Required logical evidence

A current approved registration requires:

- exactly one business registration: `dti_registration` or `sec_registration`;
- Mayor's Permit;
- BIR Certificate;
- Valid ID;
- any independently identified supporting documents required by the submission.

DTI and SEC are distinct for new submissions. Reliably preserved legacy DTI/SEC evidence continues satisfying an already approved shop until it is safely classified or replaced through an approved renewal.

### Expiration policy

| Document | Required declaration |
| --- | --- |
| Mayor's Permit | A dated expiration is required. |
| DTI or SEC registration | Expiration date or explicit “No expiration.” |
| BIR Certificate | Expiration date or explicit “No expiration.” |
| Valid ID | Expiration date or explicit “No expiration.” |
| Supporting document | Expiration date or explicit “No expiration.” |

The owner-supplied declaration is not authoritative until an authorized reviewer verifies it from the uploaded document. The application does not infer regulatory validity periods.

### Immutable version behavior

Every new or renewed upload creates a new `ShopDocument` row and file. Approval transactionally promotes the candidate to current and supersedes the predecessor without deleting or overwriting historical evidence.

```text
logical slot
├── version 1 — superseded historical evidence
├── version 2 — superseded historical evidence
└── version 3 — current approved evidence
```

Supporting-document slot identity is stable and does not depend on filename, array position, display order, or version number.

### Validity and reminders

Reviewer-verified current documents are classified as:

- `valid`;
- `valid_no_expiration`;
- `expiring_soon` (30 days or fewer);
- `expired`;
- `metadata_unverified` when the current/review metadata is incomplete.

The daily shop-compliance command sends owner reminders at 30 days, 7 days, and the expiration date using `Asia/Manila`:

```powershell
php artisan shop-documents:send-expiry-reminders
```

Delivery is chunked and database-deduplicated. Document expiration never automatically suspends, deactivates, archives, or otherwise changes shop status.

## User and shop lifecycle management

Canonical pages:

- `/admin/users`
- `/admin/shops`

Supported actions:

| Action | Meaning |
| --- | --- |
| Suspend | Temporarily blocks authority and records a suspension event/reason. |
| Reactivate | Ends the current suspension through the guarded lifecycle workflow. |
| Archive | Soft-deletes the user/shop from normal operations while retaining history; recent reauthentication required. |
| Restore | Restores an archived record through a guarded workflow; recent reauthentication required. |

Routine permanent deletion is unavailable. Archived users/shops cannot authenticate or participate in normal operations.

For employee-linked users, the Super Admin workflow changes the linked login account's platform authority safely. It does not perform Shop HR employment operations such as termination, payroll changes, leave decisions, document management, or organizational reassignment; those remain in the shop's HR domain.

## Reports, flagged accounts, and suspension appeals

Canonical pages:

- `/admin/shop-reports`
- `/admin/flagged-accounts`
- `/admin/appeals`

Report moderation now uses explicit source states, validated report ownership, transactional decisions, warning-strike uniqueness, and suspension provenance. Large report groups load bounded summaries first and one shop's report detail on demand.

Suspension appeals are linked to the suspension event that created them. An appeal can change account state only when it is still the current valid appeal for the current suspension.

```text
appeal for current suspension → decision may apply
appeal for older/superseded suspension → cannot alter account
```

Admin can view the appeal queue but cannot approve or reject appeals. Super Admin has `resolve_appeals` and may decide submitted current appeals. Appeal expiry is owned by the scheduled command, not by loading the queue:

```powershell
php artisan suspension-appeals:expire
```

## Premium plans and subscription intervention

Canonical page: `/admin/subscriptions` (Super Admin only).

The page contains bounded subscription summaries. Complete payment and refund history is loaded only for the selected subscription through `/admin/subscriptions/{subscription}/history` with an explicit safe response shape.

Supported actions:

- create, update, archive, or reactivate premium plans;
- cancel an eligible active subscription;
- apply the narrow legacy correction only to the supported legacy state;
- initiate an eligible provider-backed full refund.

Important billing rules:

- Payment history is never rewritten to simulate a refund.
- A refund is a separate append-only attempt/outcome linked to its payment/subscription.
- Provider initiation and final provider-backed outcome are audited separately around the external operation.
- Unknown/provider-pending outcomes require reconciliation; the UI must not claim success prematurely.
- Direct plan swaps and pseudo-refunds are not supported Super Admin actions.

Subscription interventions require recent password-plus-TOTP reauthentication.

## Audit, notifications, and monitoring

### Privileged audit

Canonical page: `/admin/audit`.

`PrivilegedAudit`/the privileged `activity_log` stream is the authoritative privileged history. Each committed local privileged transition and its mandatory success audit commit atomically in the same local database transaction.

Admin sees actions they performed plus operational events allowed by their fixed capabilities. Super Admin sees full privileged history. Filters are validated and cannot broaden the viewer's base visibility.

Audit history is not removed when a notification is read, dismissed, or deleted.

### Notifications

Canonical page: `/admin/notifications`.

Notifications are an operational inbox. They may link to canonical pages and may be marked read or removed from the inbox, but they are not evidence of record.

### Monitoring

Canonical page: `/admin/system-monitoring`.

Monitoring uses bounded database aggregates and at most five recent visible privileged events. It reports database connectivity, configured queue driver, failed-job count, account totals, and recent activity. It does not contain a configurable alert-rule engine.

## Canonical navigation reference

| Purpose | Canonical path | Minimum capability |
| --- | --- | --- |
| Monitoring | `/admin/system-monitoring` | `view_monitoring` |
| Registrations | `/admin/registrations` | `review_registrations` |
| Document renewals | `/admin/document-renewals` | `review_registrations` |
| Business upgrades | `/admin/business-upgrade-requests` | `review_registrations` |
| Registered shops | `/admin/shops` | `intervene_accounts` |
| Users | `/admin/users` | `intervene_accounts` |
| Shop reports | `/admin/shop-reports` | `moderate_reports` |
| Flagged accounts | `/admin/flagged-accounts` | `moderate_reports` |
| Appeals | `/admin/appeals` | `view_appeals`; decisions require `resolve_appeals` |
| Administrators | `/admin/administrators` | `manage_administrators` |
| Plans/subscriptions | `/admin/subscriptions` | `manage_plans`; interventions require `intervene_subscriptions` |
| Audit | `/admin/audit` | `view_privileged_audit` |
| Notifications | `/admin/notifications` | authenticated active MFA-complete administrator |
| Own security | `/admin/security` | `manage_own_security` |

Use route names or canonical `/admin` paths in first-party code. Do not create a new `/superAdmin` mutation or restore a retired legacy POST endpoint.

## Compatibility routes

Fifteen legacy GET/HEAD aliases remain temporarily for bookmarks and historical links:

- six `/superAdmin/*` aliases;
- eight older `/admin/*` page/detail aliases;
- `/shop/register` redirecting to `/shop-owner-register`.

They are protected according to their canonical target and preserve query strings. They never accept or replay mutations. Local evidence found no first-party callers or matching local notification links, but deployed links and traffic telemetry were unavailable, so no alias was removed.

Review date: `2026-09-13`, or after one complete production usage cycle is available. Removal requires all of:

1. zero repository callers;
2. zero deployed persisted links across relative, absolute, and query-string variants;
3. a defined observation window with no legitimate traffic;
4. tests proving the old GET is no longer needed and old mutations remain absent.

## Architecture for developers

The `super_admin` guard and `SuperAdmin` model remain. Fixed capability middleware protects focused HTTP owners:

| Domain | Primary owner |
| --- | --- |
| Administrator identity | `AdministratorManagementController` + `AdministratorIdentityService` |
| Registrations | `ShopOwnerRegistrationViewController` + registration decision service |
| Shops/users | `RegisteredShopController` / `UserInterventionController` + `AccountLifecycleService` |
| Reports/appeals | focused controllers + moderation/appeal services |
| Plans/subscriptions | `PremiumPlanController`, `SubscriptionManagementController`, `SubscriptionInterventionController` |
| Document lifecycle | owner/privileged renewal controllers + `ShopDocumentLifecycleService` |
| Private files | `PrivateSensitiveDocumentController` |
| Audit | `PrivilegedAuditController`, `PrivilegedAuditVisibility`, `PrivilegedAudit` |
| Expiry reminders | `SendShopDocumentExpiryReminders` + `ShopDocumentReminderService` |

Controllers orchestrate requests and responses. Services own transactional business invariants even when only one controller currently calls them. The HR expiry command remains separate from shop-compliance expiry.

Operational lists use server pagination with validated filters, deterministic `created_at DESC, id DESC` ordering, bounded eager loading, and database aggregates for global cards. Invalid enum filters, invalid pages, or `per_page` outside `1..100` return `422` on Phase 8 surfaces.

## Scheduled and maintenance commands

| Command | Purpose |
| --- | --- |
| `super-admin:bootstrap` | Interactively create the first pending Super Admin. |
| `super-admin:rotate-compromised-credential` | Rotate one privileged credential and invalidate database sessions. |
| `security:migrate-sensitive-documents-private` | Copy/verify sensitive files into private storage and update disk metadata safely. |
| `super-admin:reconcile-phase-two-state` | Reconcile reliable legacy suspension/appeal/warning state. |
| `privileged-audit:import-legacy` | Import reliable legacy privileged audit history without guessing. |
| `shop-documents:reconcile-legacy` | Conservatively reconcile legacy document slots/versions. |
| `shop-documents:send-expiry-reminders` | Send deterministic 30/7/0-day shop document reminders. |
| `suspension-appeals:expire` | Expire eligible/submitted appeals after their deadline. |

Run reconciliation commands in dry-run/preview mode first where supported and keep their runbook evidence. Never place credentials or bearer tokens in command arguments, logs, or documentation.

## Production-readiness checklist

Before declaring the module production-ready, verify and record:

- production `APP_URL`, HTTPS origin, stable `APP_KEY`, and time synchronization;
- `SANCTUM_STATEFUL_DOMAINS` includes the deployed browser host when the variable is explicitly configured;
- scheduler host/process and exactly one effective shop-document reminder schedule;
- `withoutOverlapping` lock-store suitability;
- shared atomic cache before adding `onOneServer()` to the shop reminder schedule;
- queue workers, retry/backoff/timeout settings, and failed-job monitoring;
- MariaDB/MySQL version, final query plans, and lock-dependent concurrency evidence;
- private storage migration/reconciliation state and backup restoration;
- deployed compatibility-link counts and a defined telemetry observation window;
- authenticated Admin and Super Admin browser flows;
- route/config cache rebuilding during deployment.

Unknown evidence remains unknown and is not a pass.

## Cross-role session reliability deployment

Deploy the backend commit and its matching committed `public/build` assets together. Apply database and cache changes in this order:

```powershell
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Restart long-lived PHP workers, PHP-FPM, or OPcache through the mechanism supported by the production host. This prevents old middleware/controller code from running against new routes or frontend assets.

After deployment, use a private browser window and verify:

1. A customer can register, open saved addresses, choose a current/map location, and continue checkout without an incidental badge request redirecting to login.
2. An active staff account can open **Add New Product**, call its entitlement endpoint, and navigate to another authorized ERP sidebar page without losing its session.
3. An approved shop owner can navigate the owner ERP sidebar and complete one owner-scoped read action without losing its session.
4. A pending shop-owner registration can be approved by an authorized MFA-complete administrator and changes from `pending` to `approved`.

Do not bypass lifecycle checks, manually change registration status, or partially apply approval data. If approval still fails, capture the new `X-Correlation-ID` and inspect the matching server exception class after confirming migrations, caches, and PHP workers are synchronized.

## Troubleshooting quick reference

| Symptom | First checks |
| --- | --- |
| Administrator is redirected to MFA setup | Confirm account state, `mfa_confirmed_at`, encrypted secret, acknowledged recovery-code state, and session stage. Do not manually mark MFA complete. |
| High-risk action redirects to reauthentication | Complete password + fresh TOTP, then retry the action within the 15-minute window. |
| Existing cookie stops working | Check administrator status, security version, MFA stage, and privileged-session registry; next-request denial is expected after a security change. |
| Setup/reset email is missing | Inspect queue worker and failed jobs; resend through the supported flow. Never expose the bearer token. |
| Initial Super Admin setup says the link is invalid after password submission | Deploy the current setup controller, run `php artisan optimize:clear` and `php artisan config:cache`, issue a fresh setup link, and use it once in a clean browser session. If it persists, inspect `storage/logs/laravel*.log` for `Privileged setup authorization missing from session` or `Privileged setup password completion failed`; share only the correlation ID and exception class. |
| Customer, staff, or owner appears logged out during navigation | Confirm the deployment includes guard-isolated lifecycle middleware and Laravel's cookie/session middleware before authentication. Verify `APP_URL` and any explicit `SANCTUM_STATEFUL_DOMAINS`, rebuild caches, restart PHP workers, then retry in a private window. An invalid guard may be removed, but it must not invalidate another valid guard in the same session. |
| Registration approval returns `500` and remains pending | Confirm `php artisan migrate --force` completed, rebuild Laravel caches, restart PHP workers, and retry once. Use the new `X-Correlation-ID` to locate the safe server exception; do not edit registration or document rows manually. |
| Private document returns 404/403 | Verify actor capability, owner/document scope, disk/path existence, and mandatory audit availability. Do not expose a direct storage URL. |
| Renewal decision returns conflict | Reload the queue and inspect current candidate/predecessor state; another decision or promotion may already have won. |
| Refund is blocked | Inspect payment eligibility, provider identifiers, unresolved prior attempts, currency/amount, and pending subscription lifecycle children. Do not edit payment history. |
| Reminder was skipped | Confirm the document is current, approved, reviewer-verified, dated, at a 30/7/0 threshold, and belongs to an approved shop; inspect deduplication delivery history. |
| Old bookmarked URL redirects | Expected while the protected GET compatibility alias remains. Update bookmarks to the canonical `/admin` path. |

## Detailed references

- [Super Admin hardening design](superpowers/specs/2026-08-12-super-admin-hardening-design.md)
- [Phase 0 containment runbook](runbooks/super-admin-phase-0-containment.md)
- [Phase 1 identity and MFA runbook](runbooks/super-admin-phase-1-identity-mfa.md)
- [Phase 2 state workflows runbook](runbooks/super-admin-phase-2-state-workflows.md)
- [Phase 3 audit and delivery runbook](runbooks/super-admin-phase-3-audit-delivery.md)
- [Final operations and Phase 8 evidence](runbooks/super-admin-operations.md)
- [Phase 7 structural simplification plan](superpowers/plans/2026-08-13-super-admin-phase-7-structural-simplification.md)
- [Phase 8 scale and final hardening plan](superpowers/plans/2026-08-13-super-admin-phase-8-scale-final-hardening.md)
