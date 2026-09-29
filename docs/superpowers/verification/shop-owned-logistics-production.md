# Shop-Owned Logistics Production Verification

**Date:** 2026-07-12
**Branch:** `feature/shop-owned-logistics-production`

## Passed gates

- `php artisan test tests/Feature/Logistics` — 114 tests, 319 assertions passed.
- Focused logistics/customer tracking Vitest — 2 tests passed.
- `npm run build` — production assets built successfully.
- `php artisan route:list --path=logistics --except-vendor` — 46 logistics routes registered.
- `git diff --check` — no whitespace errors before generated-build cleanup.
- Notification deduplication, tenant metrics, customer snapshot privacy, and duplicate return creation have focused regression tests.

## Blocked gates

- Repository-wide `php artisan test` is not green. Unrelated tests mutate/drop the shared SQLite schema; later tests fail with missing `shop_owners` and `permissions` tables. The isolated logistics suite remains green.
- No `.env.testing` MySQL configuration exists in this worktree, so production-like MySQL concurrency and migration up/down verification could not be run.
- The SQLite check proves duplicate-request convergence but does not replace real parallel MySQL transaction testing.

## Rollout checklist

- [x] Overdue command is scheduled every five minutes with overlap protection.
- [x] Logistics notification records are deduplicated by recipient, shipment, leg, and event type.
- [x] Dispatcher/rider/exception permissions are included in the role seeder.
- [x] Proof uploads use the configured public storage disk paths.
- [ ] Configure and run a production-like MySQL test environment.
- [ ] Fix repository-wide test isolation and obtain a clean full-suite run.
- [ ] Confirm queue worker, storage retention, monitoring owner, and rollback owner in the deployment environment.

**Decision:** Do not enable production shop-owned dispatch until every unchecked rollout item has evidence.
