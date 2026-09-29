# Platform Fee and Balance Implementation Plan

## Phase 1 — ledger, balance, thresholds, restriction

1. Add the platform fee schema and models with decimal snapshots, indexes, and
   duplicate/idempotency constraints.
2. Add a money-safe settings resolver, charge finalizer, balance projection,
   and platform-restriction service.
3. Mark marketplace/POS origins at retail and repair creation points; register
   completion observers; preserve existing VAT/payment behavior.
4. Add restriction checks at marketplace retail checkout and repair request
   creation, leaving POS routes untouched.
5. Add tenant-authorized balance/ledger API and the initial Finance page.
6. Add focused eligibility, calculation, POS exclusion, tenant isolation, and
   restriction tests; run them red-first and then green.

## Phase 2 — payments, approval, refunds, credits

1. Add business payment request state/authorization and full-balance snapshot
   validation.
2. Add individual direct payment and business finance execution using the
   existing SoleSpace PayMongo integration pattern.
3. Extend the verified webhook path with idempotent platform payment settlement
   and allocations.
4. Add refund reversal/credit handling at existing retail and repair refund
   settlement boundaries.
5. Add notifications and audit records for the payment state machine.

## Phase 3 — reliability, admin, terms, and UI completion

1. Add score factors/history, daily recalculation, and configurable tier
   recommendations without automatic limit changes.
2. Add admin review/approval for limit changes and platform settings.
3. Add threshold-crossing notifications, reminders, restriction history, and
   terms/version acceptance.
4. Complete business/individual owner and admin ledger/payment pages.
5. Run the full relevant Laravel and frontend quality gates, then perform the
   sequential standards/spec/security/simplification review.

## Verification sequence

- `php artisan test --filter=PlatformFee`
- `php artisan test --filter=PlatformBalance`
- `php artisan route:list --path=finance`
- `pnpm run test:frontend -- --runInBand` (where supported by the repository)
- `git diff --check`
