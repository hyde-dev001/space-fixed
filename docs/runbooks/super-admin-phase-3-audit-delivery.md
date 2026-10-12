# Super Admin Phase 3 Audit and Delivery Runbook

This runbook covers deployment and recovery for the Phase 3 privileged transaction, audit, legacy-import, and queued-delivery changes. The authoritative local decision is the committed business mutation plus its `privileged` activity row. Mail delivery is asynchronous and must never be used to decide whether a local mutation committed.

## 1. Readiness and backup

Before the change window:

1. Put the application into the approved maintenance or controlled-deploy mode and record the release identifier, operator, start time, and rollback contact.
2. Confirm the working release, PHP/Composer dependencies, queue worker version, database connection, cache connection, and scheduler host. Do not print or paste secrets while checking configuration.
3. Confirm that `activity_log`, legacy `audit_logs`, `jobs`, and `failed_jobs` are included in the database backup. Take a fresh backup and verify that the restore process can read it before changing the production schema.
4. Check pending migrations and queue health:

   ```bash
   php artisan migrate:status
   php artisan queue:failed
   php artisan schedule:list
   ```

5. Confirm the production queue connection is the intended durable connection, that the queue worker can write/read `jobs` and `failed_jobs`, and that the connection's `retry_after` exceeds the job timeout. Phase 3 jobs use three attempts with backoff intervals of 10, 30, and 90 seconds.

Do not deploy if the backup cannot be restored, the production queue connection is unverified, or `failed_jobs` is unavailable.

## 2. Deploy order

1. Apply additive migrations before starting the new application code:

   ```bash
   php artisan migrate --force
   ```

   The legacy provenance migration adds nullable provenance fields and indexes. It does not rewrite or delete existing history.

2. Deploy the application release and dependencies. Clear/rebuild the application caches using the normal release procedure; never put credentials, queue payloads, or document paths into deploy logs.
3. Restart or reload queue workers after the application release is live so workers run the same code as the web process:

   ```bash
   php artisan queue:restart
   ```

   Then confirm the process supervisor has started workers for the production queue and that old workers have exited. Do not run a worker with a different release against the same queue during recovery unless the job payload and code are known to be compatible.
4. Verify the scheduler configuration. Run `php artisan schedule:list` on the scheduler host and confirm that exactly one scheduler invokes the application. Multiple schedulers are safe only when a shared cache/database atomic lock has been proven for every scheduled task.

## 3. Legacy privileged-audit import

The import is dry-run by default. Start with a bounded sample:

```bash
php artisan privileged-audit:import-legacy --limit=500 --chunk=200
```

Review `would_import`, `already_imported`, `skipped`, and each `skipped_reason[...]` count. A dry run must not create privileged activity rows, alter `audit_logs`, or delete source history.

Interpret skip reasons as follows:

| Reason | Meaning and action |
| --- | --- |
| `action_not_allowlisted` | The legacy action is outside the frozen privileged mapping. Leave it in `audit_logs`; do not force-map it. |
| `malformed_json` | The source `data` or `metadata` is not valid JSON. Preserve the row for investigation. |
| `target_type_invalid` | The target type does not match the known action or account shape. Do not guess the subject. |
| `target_conflict` | Target/object IDs or related target identifiers disagree or are unusable. Reconcile the source row before any manual decision. |
| `target_missing` | The referenced user or shop owner no longer exists. Retain the source row and record it as unreconciled. |
| `report_outcome_unrecognized` | A report decision is not one of the normalized outcomes. Do not import an invented outcome. |
| `actor_unknown` | No valid Super Admin actor can be resolved. Leave the row out of authoritative privileged history. |
| `actor_conflict` | Actor identifiers in the source disagree. Resolve the source identity before retrying. |
| `appeal_missing` | The appeal or its unique account relationship cannot be resolved. Do not select an arbitrary appeal. |
| `actor_ambiguous` | A legacy integer identity could refer to both a user and a Super Admin. Treat it as ambiguous; never infer the privileged actor. |
| `import_failed` | A row mapped safely but persistence failed. Inspect the server-side exception and database health, then rerun the bounded import after the cause is fixed. |

Every skip is a reconciliation item, not an instruction to weaken the mapper. The command prints grouped counts only; inspect source records through an access-controlled operational process.

When the dry-run sample is understood, apply the import in bounded batches:

```bash
php artisan privileged-audit:import-legacy --apply --chunk=200
```

For a staged rollout, add `--limit` to each approved batch and record the range processed. Then rerun the same dry-run or a full dry-run:

```bash
php artisan privileged-audit:import-legacy --limit=500 --chunk=200
```

Rows already imported must appear as `already_imported`; rerunning must not create a second privileged activity row. Confirm imported rows retain their original timestamps and `audit_logs` remains intact. Never delete source or imported history as part of an import retry or rollback.

## 4. Queue and failed-delivery recovery

After deployment, verify the configured production queue connection, worker count, retry/backoff settings, and access to the `failed_jobs` table. A queued Phase 3 job is encrypted, unique by delivery type/business event/recipient/channel, and rechecks privileged-recipient eligibility before fan-out.

Inspect failed work without dumping serialized payloads:

```bash
php artisan queue:failed
```

For a targeted recovery:

1. Confirm the local business state and authoritative success audit already committed. Use the business identity and correlation ID from safe monitoring/audit fields; do not inspect or copy the encrypted job payload into tickets or logs.
2. Confirm the recipient and queue/provider configuration are healthy. A retry must not re-run the business mutation or create a second success audit.
3. Retry only the approved failed-job ID:

   ```bash
   php artisan queue:retry <failed-job-id>
   ```

4. Recheck `queue:failed` and inspect the delivery by `business_event_id` and `correlation_id` in the safe operational record. If it fails again, leave it failed for investigation rather than repeatedly retrying an unhealthy provider.

Do not use `queue:retry all` for a privileged incident without reviewing each failed job. Do not manually reconstruct a secret-bearing setup or reset payload outside the encrypted application job path.

## 5. At-least-once provider acknowledgement limitation

The application guarantees an atomic local decision/audit and an after-commit delivery attempt, not exactly-once provider acknowledgement. A provider or network timeout can occur after the provider accepted a message, so a retry can produce a duplicate external message. The job's unique key prevents duplicate enqueueing for the same business event and recipient while the unique lock is held, but it cannot reverse an acknowledgement already accepted by an external provider.

Reconcile suspected duplicates using the stable business event, recipient identity, correlation ID, queue/failed-job status, and provider delivery reference available to authorized operators. Do not reapply the underlying business decision or create a second audit row. If a resend is approved, use the canonical application delivery path and document the reconciliation decision.

## 6. Rollback

An application release may be rolled back if necessary while the additive provenance tables/columns/indexes and the source `audit_logs` remain in place. Before rollback, stop or reload workers onto the compatible application release and preserve failed-job IDs for recovery.

Do not roll back by deleting the additive schema, imported activity rows, or source `audit_logs`. Never truncate `activity_log`, `audit_logs`, `jobs`, or `failed_jobs` to make a deployment appear clean. A later forward migration is required for any schema correction.

## 7. Scope boundary

Phase 3 certifies local privileged state transitions, authoritative privileged audit history, and durable operational delivery handling. Subscription cancellation, plan switching, refunds, provider calls, and other subscription money movement remain explicitly out of scope. Phase 5 owns the transaction, audit, and reconciliation design for subscription money movement.

## 8. Post-deploy evidence

Record the release identifier, migration result, import dry-run/apply summaries, worker restart time, scheduler owner, queue connection verification, and any targeted retries. For each recovered delivery, record only the safe business identity, recipient identity, correlation ID, result, and timestamp—never raw queue payloads, credentials, tokens, exception text, private paths, or provider payloads.
