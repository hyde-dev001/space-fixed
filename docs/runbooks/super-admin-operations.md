# Super Admin Billing Operations

This runbook covers bounded recovery for premium subscription billing. It does not authorize provider refunds, plan changes, paid-history edits, or production database correction outside the workflows below.

## Legacy subscription inventory

Run the legacy reconciliation command in dry-run mode first:

```powershell
php artisan premium-billing:reconcile-legacy --limit=100
```

Review the reported reliable, ambiguous, duplicate, and unchanged counts. Apply only the reviewed bounded batch:

```powershell
php artisan premium-billing:reconcile-legacy --apply --limit=100
```

Never use the command to guess a provider payment ID, amount, owner, plan, or entitlement date. Ambiguous `deactivated` rows remain visible for the narrow privileged correction workflow.

## Provider reconciliation

Inspect the local pending, processing, or unknown attempt state through the privileged billing screen and audit history, then run a bounded provider reconciliation:

```powershell
php artisan premium-billing:reconcile-provider --limit=100
```

The command retrieves known provider refund references or lists refunds for the original payment. It never creates a second refund request. Re-run only after the provider state or webhook delivery has changed; an unknown attempt remains blocked from blind retry until its outcome is known.

The command prints local attempt counts and statuses only. It must not print provider payloads, secrets, payment details, owner personal data, or exception text.

## Provider outage and irreconcilable state

- A timeout is recorded as `unknown`; retain entitlement and do not submit another refund with a new key.
- A known provider failure preserves the paid payment and subscription state and may be retried only as a new, explicitly reviewed attempt.
- A provider amount, currency, payment, or refund-reference mismatch is not auto-corrected. Preserve the attempt and escalate for provider reconciliation.
- If a provider refund succeeded while the local finalization failed, reconcile the existing provider refund reference before enabling any further intervention.

Do not delete refund attempts, set `paid_amount` to zero, reverse provider history locally, or restore withdrawn direct plan-swap/pseudo-refund endpoints during recovery.

## Safety checks before enabling interventions

Confirm production PayMongo credentials and webhook signature verification are configured, the migration is deployed, and the focused Phase 5 billing tests pass. Use sandbox/test credentials for browser verification. Never send a real refund during local verification.

## Legacy shop-document reconciliation

Run the Phase 6 reconciliation in read-only mode first and review only the reported counts and local document IDs:

```powershell
php artisan shop-documents:reconcile-legacy --chunk=100
```

Apply only a reviewed bounded owner batch. The command caps chunks at 1,000 and accepts a positive `--shop-owner-id` filter:

```powershell
php artisan shop-documents:reconcile-legacy --apply --shop-owner-id=123 --chunk=100
```

The reconciler may assign stable slots, versions, predecessor links, and `expiration_mode=unknown` when ordering is deterministic. It never infers DTI versus SEC, expiration dates, missing files, or a current row from public/ambiguous evidence. Unresolved IDs require operator review; do not manually rewrite historical rows or delete their files.

Re-run the same reviewed batch to verify it is inert. Approved shops retain a private legacy DTI/SEC compatibility label until a concrete DTI or SEC renewal is reviewed. Upgrade evidence reuse remains limited to current approved private documents; public, missing, or superseded files must be uploaded again.

## Privileged runtime ownership

Each responsibility has one runtime owner. Phase 7 extracts HTTP orchestration without changing the existing services, authorization boundaries, audit semantics, or document lifecycle rules.

| Responsibility | Runtime owner |
| --- | --- |
| Owner registration | `ShopOwnerAuthController` + `ShopDocumentLifecycleService` |
| Owner renewal submission | `ShopOwnerDocumentRenewalController` + `ShopDocumentLifecycleService` |
| Registration review | `superAdmin/ShopOwnerRegistrationViewController` + registration decision service |
| Renewal review | `superAdmin/ShopDocumentRenewalController` + `ShopDocumentLifecycleService` |
| Private document access | `PrivateSensitiveDocumentController` |
| Shop expiry detection and reminders | `SendShopDocumentExpiryReminders` + `ShopDocumentReminderService` |
| HR expiry processing | `CheckDocumentExpiry` (`EmployeeDocument` only) |
| Notification persistence | Existing `Notification` model and notification infrastructure |
| Privileged audit writes | `PrivilegedAudit` |
| Legacy audit import | `ImportLegacyPrivilegedAudit` (bounded reconciliation only) |

The shop-document reminder command and `hr:check-document-expiry` are separate workflows. Neither command calls the other or uses a shared generic expiry service. The shop reminder command is scheduled once daily at `01:00` in the configured shop timezone, operates on shop-document eligibility, and does not mutate shop status. The HR command remains responsible for employee-document expiry only.

Private document bytes remain available only through scoped routes that enforce the existing actor, status, object, and audit rules. Runtime privileged operations write through `PrivilegedAudit`; the legacy importer is dry-run by default, applies only a bounded reviewed batch, records provenance, and preserves the source `audit_logs` rows.

## Compatibility GET alias inventory

The following aliases are temporary, capability-protected `GET|HEAD` redirects. They preserve path parameters and query strings, do not resolve models, call controllers/services, or make business decisions, and have no mutation aliases.

| Legacy path | Canonical target | Capability |
| --- | --- | --- |
| `/admin/admin` | `/admin/administrators` | `manage_administrators` |
| `/admin/create-admin` | `/admin/administrators/create` | `manage_administrators` |
| `/admin/shop-owner-registration-view` | `/admin/registrations` | `review_registrations` |
| `/admin/registered-shops` | `/admin/shops` | `intervene_accounts` |
| `/admin/shops/{id}/details` | `/admin/shops/{id}` | `intervene_accounts` |
| `/admin/user-management` | `/admin/users` | `intervene_accounts` |
| `/admin/subscription-management` | `/admin/subscriptions` | `manage_plans` |
| `/admin/data-reports` | `/admin/audit` | `view_privileged_audit` |
| `/superAdmin/super-admin-user-management` | `/admin/users` | `intervene_accounts` |
| `/superAdmin/shop-owner-registration-view` | `/admin/registrations` | `review_registrations` |
| `/superAdmin/flagged-accounts` | `/admin/flagged-accounts` | `moderate_reports` |
| `/superAdmin/system-monitoring-dashboard` | `/admin/system-monitoring` | `view_monitoring` |
| `/superAdmin/notification-communication-tools` | `/admin/notifications` | Canonical route boundary |
| `/superAdmin/data-report-access` | `/admin/audit` | `view_privileged_audit` |
| `/shop/register` | `/shop-owner-register` | Public registration boundary |

On 2026-08-13, a read-only inventory of the local SQLite snapshot found zero matching relative or absolute legacy `/superAdmin/` or `/admin/` notification action URLs and zero `/admin/shops/*/details` action URLs. This is local evidence only; production rows, external bookmarks, and other deployed databases were not available from this worktree. Historical notification URLs were not rewritten, so the aliases remain until Phase 8 has removal evidence.

Phase 8 may retire an alias only after source references are absent, persisted notification URLs are zero across relative, absolute, and query-string variants, redirect/bookmark telemetry has been reviewed, and a route test proves the old path is no longer required. The inventory must be repeated against the deployed database before removal; a non-zero historical-link count is a reason to retain the safe redirect, never to rewrite historical audit or notification evidence.
