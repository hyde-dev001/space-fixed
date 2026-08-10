# Shop module rollout runbook

This change introduces shop-level module state, upgrade requests, and an
optional route gate. The enforcement flag is deliberately off by default.
Do not turn it on as part of an ordinary code deploy.

## Production order

1. Deploy the schema and application code with
   `SHOP_MODULE_ENFORCEMENT_ENABLED=false`.
2. Run the migrations:

   ```sh
   php artisan migrate --force
   ```

3. Initialize missing rows. The command is idempotent and does not overwrite
   an existing disabled state:

   ```sh
   php artisan shop-modules:backfill
   ```

4. Verify without writing rows. A non-zero exit means the deployment is not
   ready for enforcement:

   ```sh
   php artisan shop-modules:backfill --verify
   ```

5. Smoke-test, while enforcement is still off:
   - an owner can open Settings and see the authoritative module states;
   - an employee can still use core profile, password, attendance, and
     notification pages;
   - a SuperAdmin can open the Business Upgrade Requests queue and download a
     permitted evidence file;
   - one representative owner/employee route per module works for an eligible
     shop and is denied for a disabled module;
   - the customer storefront, checkout, queued jobs, scheduled jobs, and
     webhook endpoints still work.
6. Separately authorize the flag change, set
   `SHOP_MODULE_ENFORCEMENT_ENABLED=true`, and refresh the application
   configuration cache:

   ```sh
   php artisan config:cache
   ```

7. Repeat the smoke tests and monitor 403 responses, application errors,
   queue failures, and module-toggle/upgrade audit events.
8. If the gate causes an unexpected regression, roll it back by setting the
   flag to `false` and refreshing the configuration cache. Do not delete module
   rows, upgrade requests, evidence, employee permissions, or operational data
   as a rollback step.

## Data-integrity checks

Run the backfill verification first. For a database-level review, run the
following checks with the catalog keys from `config/shop_modules.php`:

```sql
-- Duplicate owner/module rows (the unique constraint should return no rows).
SELECT shop_owner_id, module_key, COUNT(*) AS row_count
FROM shop_owner_modules
GROUP BY shop_owner_id, module_key
HAVING COUNT(*) > 1;

-- Unknown keys (the result should be empty).
SELECT DISTINCT module_key
FROM shop_owner_modules
WHERE module_key NOT IN (
  'retail_operations', 'repair_operations', 'hr_employees', 'finance',
  'crm', 'inventory', 'procurement', 'logistics'
);

-- More than one pending request for one owner (the result should be empty).
SELECT shop_owner_id, COUNT(*) AS pending_count
FROM shop_owner_upgrade_requests
WHERE status = 'pending'
GROUP BY shop_owner_id
HAVING COUNT(*) > 1;

-- Enabled rows for an ineligible owner require investigation before enabling
-- enforcement. Compare registration_type/business_type with the catalog.
SELECT m.shop_owner_id, m.module_key
FROM shop_owner_modules AS m
JOIN shop_owners AS o ON o.id = m.shop_owner_id
WHERE m.enabled = 1
  AND (o.status <> 'approved'
    OR (m.module_key IN ('hr_employees', 'finance', 'crm', 'inventory', 'procurement', 'logistics')
        AND o.registration_type <> 'company')
    OR (m.module_key = 'retail_operations' AND o.business_type NOT IN ('retail', 'both'))
    OR (m.module_key = 'repair_operations' AND o.business_type NOT IN ('repair', 'both')));
```

Do not put owner names, emails, document paths, or evidence contents in
deployment logs. Record counts and error codes only.

## Queue and notification verification

The application currently defaults to Laravel's database queue and stores
failed jobs in `failed_jobs`. Upgrade-request notifications are dispatched
after the transaction commits. After a worker processes a review or submission:

1. Confirm the expected notification/audit event exists and the recipient is
   the intended active SuperAdmin or shop owner.
2. Confirm the related request and module state are committed before the
   notification is visible.
3. Check the queue worker output and the failed-job count in the existing
   SuperAdmin system-monitoring view.
4. For a failed job, inspect the exception and retry the specific UUID with
   `php artisan queue:retry <uuid>` after correcting the cause. Do not blindly
   replay all failed jobs; remove a permanently invalid job with the existing
   `queue:forget` command after recording the incident.

Use the repository's existing worker/supervisor process and its configured
`QUEUE_CONNECTION`; do not introduce a new queue backend for this rollout.

## Rollback signals

Roll back the flag (without deleting data) when any of these persist after a
configuration refresh:

- eligible owner/employee routes return `MODULE_STATE_MISSING` after a
  successful backfill;
- a core Settings, customer, SuperAdmin, webhook, scheduled, or queue flow
  returns a module denial;
- cross-shop requests are not denied, or a disabled module remains reachable;
- upgrade review/notification jobs accumulate in `failed_jobs`;
- module-toggle audit events do not match the authoritative state returned by
  the API.

After rollback, keep the rows and requests intact, correct the classification
or backfill issue, rerun `--verify`, and repeat the smoke tests before a later
flag change.
