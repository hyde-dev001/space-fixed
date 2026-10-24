# Shop Owner Phase 1 State Rollout

This runbook covers the report-first reconciliation of legacy Shop Owner Order and Employee state. It is a rollout gate for stricter canonical policies and validation; it is not a replacement for Order, Leave, Payroll, Refund, or other domain workflows.

## Safety boundary

The command is report-only unless `--apply` is explicitly supplied. Apply mode may normalize only Employee `on_leave` and `on-leave` to `active`. It does not delete or alter Leave records. It never mutates unknown Employee values or the legacy Order `refund` value. An unresolved row is reported with a `rollout_blocker` disposition and is enforcement-blocked until an authoritative, reviewed disposition exists.

The command is scoped by shop owner and bounded by `--chunk`. Apply batches are transactional: a failed shop batch rolls back as a unit, while already committed earlier batches remain committed. Re-running apply is safe and idempotent.

## Gates

Complete and record each gate in order. Stop the rollout when a gate lacks evidence.

1. **Deploy observation support.** Deploy the report-only command and the additive Employee enum compatibility migration. Do not remove `on_leave` or Order `refund` in this release.

2. **Inventory by domain and shop.** Run report-only for each target shop and domain, then run the all-shop summary:

   ```powershell
   php artisan shop-owner:reconcile-phase-one-state --domain=orders --shop-owner-id=<SHOP_ID>
   php artisan shop-owner:reconcile-phase-one-state --domain=employees --shop-owner-id=<SHOP_ID>
   php artisan shop-owner:reconcile-phase-one-state --domain=all --chunk=500
   ```

   Record each run ID, shop/domain counts, reasons, unresolved rows, and the corresponding structured log entry. Do not record credentials, contact details, proof contents, or other personal data.

3. **Disposition every unresolved row.** Have the domain owner classify every unresolved row as exactly one of:

   - `manual_correction` — an approved corrective workflow is required;
   - `accepted_legacy_exception` — the legacy value is intentionally retained with an owner and review date;
   - `deferred` — an owner, reason, and target release are recorded;
   - `rollout_blocker` — enforcement cannot proceed.

   The current command defaults unresolved rows to `rollout_blocker`. A disposition does not authorize direct database editing or make an ambiguous `refund` row canonical; authoritative Order, Refund, Return, Payment, Leave, or Payroll evidence must be used by the owning workflow.

4. **Deploy compatible policies and callers.** Deploy the canonical policies, projections, and caller migrations with compatibility responses. Keep legacy enum values readable until the disposition inventory is complete.

5. **Run behavioral evidence.** Run characterization and equivalence suites for the affected domains before mutation:

   ```powershell
   php artisan test tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php --compact
   php artisan test tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php --compact
   ```

6. **Apply bounded shop batches.** Obtain an approved change window and apply only reviewed shop scopes. Prefer one domain and a small `--chunk` per invocation so a failure is easy to isolate:

   ```powershell
   php artisan shop-owner:reconcile-phase-one-state --domain=employees --shop-owner-id=<SHOP_ID> --chunk=25 --apply
   ```

   Review the run ID, updated count, unresolved count, and logs after every batch. Never run `--apply` against an unreviewed or non-test database.

7. **Rerun and close the data gate.** Repeat the report for the same shop/domain scopes. Require zero additional normalizations and zero undispositioned rollout-blocking rows. Confirm that Leave records, Refund/Return/Payment evidence, and unknown values are unchanged unless an authoritative domain correction was separately approved.

8. **Enable Logistics and authentication enforcement.** Enable the stricter Logistics responsibility and sign-in context validation only after the target scopes pass the reconciliation and compatibility gates.

9. **Monitor denial categories.** Monitor authorization-denial and unresolved-row counts by domain and shop. Stop rollout on an unexplained spike, cross-shop denial, wrong-context authentication behavior, or any mutation of an unresolved row.

10. **Defer constraint removal.** In a later release, consider removing Employee `on_leave` and eventually Order `refund` only after every caller and historical row has authoritative canonical evidence and the disposition inventory is complete.

## Rollback and correction

For a policy or presentation regression, revert the policy/caller/presentation release where safe and leave correctly normalized rows in their canonical state. Do not reverse correct normalization as an automatic rollback step. If a normalization was incorrect, use a separately reviewed compensating command that restores the intended domain state and preserves Leave, Refund, Return, Payment, audit, and payroll evidence.

If an apply batch fails, keep the failed batch unchanged, capture its run ID and database error, repair the cause, and rerun the same bounded scope. Earlier committed batches do not need to be reversed solely because a later batch failed.
