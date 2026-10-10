# SoleSpace Phase 2 State and Workflow Runbook

This runbook covers the Phase 2 privileged workflows for registration decisions, account suspension and appeals, reversible archive/restore, shop-report moderation, and flagged-account review. It assumes Phase 0 containment and Phase 1 privileged identity/MFA controls are deployed.

## Security floor

- All operational routes remain behind completed privileged authentication, an active privileged account, MFA, and the capability boundary. High-risk mutations also require recent reauthentication.
- User and shop archive operations are reversible soft deletes. There is no administrator archive/delete workflow and no user/shop hard-delete route.
- Private registration documents remain behind authorization-checked routes. Never recreate `/storage/` links for sensitive documents.
- Suspension, appeal, moderation, and privileged-audit evidence is authoritative state. Do not erase it to make a rollback appear clean.
- Audit properties may contain event names and aggregate identifiers only. Do not put passwords, MFA secrets, recovery plaintext, bearer tokens, private document paths, appeal tokens, recipient addresses, or copied request payloads into logs, queues, or audit rows.
- Notifications are post-commit delivery. A mail failure must be retried operationally; it must not be repaired by replaying an already-committed state mutation.

## Preflight and stop conditions

The named operator owns the maintenance window and evidence capture. A second operator independently checks the backup, privileged account, and dry-run counts before apply.

Complete and record every item before production traffic resumes:

1. Confirm the deployed revision includes Phase 0, Phase 1, and Phase 2 code, migrations, tests, and this runbook.
2. Verify a current database and storage backup. Test restoring both to a disposable target and record the result and backup identifiers.
3. Capture the production database engine/version and rehearse all migrations on a copy of that engine. Do not infer MySQL compatibility from SQLite tests.
4. Confirm `APP_KEY`, session storage, queue connection, mail configuration, worker health, failed-job visibility, and application time synchronization.
5. Confirm at least one recoverable MFA-complete Super Admin and one independently verified operator. Do not bypass MFA or create a password-only emergency path.
6. Capture baseline counts before reconciliation:
   - users and shops by status, including soft-deleted rows;
   - live and terminal appeals, duplicate live appeals, expired appeals, and suspended accounts with no safely attributable appeal;
   - legacy warning audits and ambiguous employee emails;
   - candidate archives, open report groups, and flagged review reports.
7. Run `composer audit --locked`. Stop on an unresolved production-reachable advisory. Remediate it, or obtain a documented security risk acceptance naming the advisory, affected package/version, reachability, compensating controls, owner, expiry date, and monitoring plan before release approval.
8. Run the focused Phase 2 invariant tests and confirm there are no failures. Existing warnings must be counted and explained; warnings are not silently treated as a pass with zero warnings.
9. Review the route and schedule inventory. Confirm appeal expiry is scheduled and that privileged routes have the expected Phase 1 middleware.
10. Announce the maintenance window and record the revision, operator, reviewer, backup IDs, database profile, queue worker status, and start time.

Stop and keep the maintenance boundary in place on migration failure, failed restore verification, missing MFA access, duplicate current suspension references, unresolved reconciliation command failure, unexplained count drift, ambiguous employee attribution on a requested transition, an invariant-test failure, an unresolved security advisory, or a queue/audit storage outage.

## Rollout order

Run the following sequence in a maintenance window. Save command output and the generated reconciliation operation UUID with the deployment record.

```text
enter maintenance
-> deploy code and additive migrations
-> run migration/status checks
-> run reconciliation dry run and save output
-> review ambiguous IDs and count deltas
-> run reconciliation --apply; the command generates and prints its operation UUID
-> rerun reconciliation dry run; expect zero pending changes
-> run focused state and invariant tests against the deployment profile
-> verify route inventory, schedule inventory, queue worker, and failed jobs
-> verify both privileged roles and next-request denial after logout/session invalidation
-> exit maintenance
```

Recommended commands:

```powershell
php artisan migrate --force
php artisan migrate:status
php artisan help super-admin:reconcile-phase-two-state
php artisan super-admin:reconcile-phase-two-state
php artisan super-admin:reconcile-phase-two-state --apply
php artisan super-admin:reconcile-phase-two-state
php artisan help suspension-appeals:expire
php artisan schedule:list
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
```

The dry run is read-only. Do not pass an operation or correlation UUID to the reconciliation command; it must generate one server-side per execution. Review `operator_review_required` cases individually. A suspended account without a safely attributable live appeal remains without a fabricated appeal, token, expiry, or history until an authorized operator makes a real lifecycle decision.

There is no irreversible cleanup in this rollout. Do not hard-delete legacy rows, old audit evidence, documents, suspensions, appeals, moderation actions, or archived accounts as part of reconciliation.

## Normal operations after rollout

### Privileged account lifecycle

- Active users and approved shops may be suspended with a required reason.
- Suspended users and shops may be reactivated with a required reason. Pending or rejected shops do not receive a general activation path.
- Active or suspended users and shops may be archived with a required reason after recent reauthentication.
- Archived records appear only in explicit privileged management reads using the `active`, `archived`, or `all` lifecycle filter. Normal application queries retain the soft-delete scope.
- Restore requires a reason and recent reauthentication. Restore preserves the underlying account status; restoring an archived suspended record does not claim that it was activated.
- Employee status synchronized from a privileged suspension is restored only while its provenance marker still points to that exact suspension. Independent HR actions clear that marker and cannot be overwritten by an old account suspension.

### Appeals and expiry

- An appeal is bound to the current suspension identity, not merely an account ID.
- Reviewers must be Super Admins with the resolution capability. Approval reactivates the exact current suspension; rejection leaves it suspended.
- Repeated identical submissions are idempotent. Conflicting retry messages are conflicts. Expired submissions are persisted as expired before the request returns its terminal response.
- Run `php artisan suspension-appeals:expire` from the scheduler or as an explicitly monitored operational job. It is bounded and safe to rerun.

### Reports and flagged accounts

- A moderation decision submits the exact sorted report-ID set rendered to the operator. Reports arriving later remain open and are not silently included.
- Warning strikes and moderation actions are derived from server state and exact report sets. Do not accept a client-supplied strike number or decision identity.
- Flagged-account review actions must preserve the report group, account identity, actor, and privileged audit evidence. Do not treat a client-side dismissal as a server decision.

## Monitoring and evidence

During rollout and normal operations, monitor:

- reconciliation output, operation UUID, proposed/applied counts, ambiguous IDs, and rerun zero-change output;
- suspension, reactivation, archive, restore, appeal, moderation, and flagged-account audit events;
- mandatory-audit failures and transaction rollbacks;
- duplicate current-suspension references, duplicate live appeals, duplicate warning-strike numbers, and employee provenance mismatches;
- queue failures, post-commit notification failures, appeal-expiry failures, and application exceptions;
- privileged login/MFA/session invalidation and recent-reauthentication failures;
- 403/409/422 lifecycle responses and repeated attempts against archived, pending, rejected, or stale aggregates.

Retain the deployment revision, migration output, before/after counts, dry-run and apply output, operation UUID, focused test output, route/schedule inventory, queue check, backup identifiers, and reviewer sign-off. Redact secrets and private document paths before storing evidence.

## Forward recovery and rollback limits

- Before application traffic resumes, a schema rollback may be considered only if no Phase 2 rows were committed and the rollback was rehearsed on the same database engine. Verify this from the migration ledger and database counts.
- After suspension, appeal, moderation, reconciliation, or audit rows exist, prefer a forward corrective migration or code fix. Never delete evidence to force a schema rollback.
- Restoring a database backup also requires restoring matching application code, storage, queue state as appropriate, and invalidating affected privileged sessions.
- Reconciliation is designed to be rerunnable. If counts differ, rerun the dry run and investigate the aggregate IDs; do not hand-edit rows to satisfy an expected number.
- If a notification fails after commit, retry delivery through the queue/failed-job procedure. Do not replay the approval, suspension, appeal, moderation, archive, or restore mutation.
- If an archived account is mistakenly restored, use the authorized archive workflow with a new reason and audit trail. Do not call model `forceDelete()` or manipulate `deleted_at` directly from a console shortcut.
- If an appeal decision races a manual reactivation or a lifecycle transition returns `409`, reload the aggregate and make one new authorized decision. Do not retry a stale request blindly.
- If an employee is ambiguous, inactive, terminated, or independently changed, stop the requested linked restoration and escalate for operator review. Do not guess the employee identity.

## Verification checklist

Run from the deployed revision and attach exact output:

```powershell
composer audit --locked
php artisan help super-admin:reconcile-phase-two-state
php artisan help suspension-appeals:expire
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan schedule:list
php artisan migrate:status
```

The route inventory must show no administrator archive/delete endpoint, no user/shop hard-delete route, the Phase 1 privileged middleware boundary, and the recent-reauthentication boundary on high-risk lifecycle mutations. The schedule inventory must show bounded appeal expiry. Any security advisory or test harness failure that remains must have a separate reviewed exception with an owner and expiry; it is not silently accepted by this runbook.
