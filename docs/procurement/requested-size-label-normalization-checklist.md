# One-Time Deployment Checklist: Requested Size Label Normalization

Use this checklist once per environment (staging/production) to normalize historical `requested_size` values to canonical format such as `US 8`, `EU 40`, etc.

## Scope

This command updates only these tables:
- `stock_request_approvals`
- `purchase_requests`
- `purchase_orders`

Command:
- `php artisan sizes:normalize-requested-size-labels`

## Preconditions

- Application code with `NormalizeRequestedSizeLabels` command is deployed.
- Database migration state is up to date.
- Run during low-traffic window.

## Checklist

- [ ] 1) Take DB backup/snapshot
- [ ] 2) Run dry-run preview
- [ ] 3) Review summary counts and sample lines
- [ ] 4) Run real normalization command
- [ ] 5) Verify post-run counts and sample values
- [ ] 6) Record execution details in deployment notes

## Execution Steps

### 1) Dry-run (no writes)

```bash
php artisan sizes:normalize-requested-size-labels --dry-run
```

Expected: summary printed with `scanned`, `updated`, and `skipped` per table.

### 2) Apply updates

```bash
php artisan sizes:normalize-requested-size-labels
```

Expected: same summary format; `updated` reflects actual writes.

### 3) Verify data

Run a quick count check:

```bash
php artisan tinker --execute="echo 'stock_request_approvals=' . \Illuminate\Support\Facades\DB::table('stock_request_approvals')->whereNotNull('requested_size')->count() . PHP_EOL; echo 'purchase_requests=' . \Illuminate\Support\Facades\DB::table('purchase_requests')->whereNotNull('requested_size')->count() . PHP_EOL; echo 'purchase_orders=' . \Illuminate\Support\Facades\DB::table('purchase_orders')->whereNotNull('requested_size')->count() . PHP_EOL;"
```

Optional sample rows for manual inspection:

```bash
php artisan tinker --execute="\Illuminate\Support\Facades\DB::table('stock_request_approvals')->whereNotNull('requested_size')->select('id','inventory_item_id','requested_size')->orderByDesc('id')->limit(20)->get()->each(fn($r)=>print($r->id.' | '.$r->inventory_item_id.' | '.$r->requested_size.PHP_EOL));"
```

## Safety / Rollback

- Command is idempotent (safe to run more than once).
- If rollback is needed, restore from pre-run DB backup.

## Notes

- Existing canonical values are preserved.
- For values without explicit system, command attempts to infer from `inventory_sizes`; if not inferable, defaults to `US`.
- Values that would exceed the DB column limit are skipped and reported.
