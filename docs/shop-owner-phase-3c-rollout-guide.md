# Shop Owner Phase 3C Rollout Guide

## Scope

Phase 3C adds `Waiting on Others` to the existing Shop Owner Action Center. It is a request-time read of authoritative domain state. It does not create Action Center persistence, jobs, polling, notifications, mutation controls, or a second authorization system.

The initial sources are:

| Source | Adapter key | Responsibility | Authoritative destination |
| --- | --- | --- | --- |
| Pending compliance renewal review | `pending_compliance_renewals` | `super_admin` | `/shop-owner/settings/policies-compliance` |
| Order refund recovery | `waiting_order_refund_recovery` | `finance` or `payment_recovery` | existing owner refund approval route |
| Repair refund recovery | `waiting_repair_refund_recovery` | `finance` or `payment_recovery` | existing owner repair refund route |
| Active logistics recovery | `active_logistics_recovery` | `rider` or `dispatcher` | `/shop-owner/logistics/shipments` |

Every item is classified into exactly one primary bucket. Owner decisions take precedence over Waiting; a deterministic other party takes precedence over an unowned urgent exception.

## Defaults and deployment order

The thesis deployment uses committed application defaults in `config/owner_action_center.php`:

- the Action Center is enabled;
- the existing decision coverages remain enabled;
- `urgent_exceptions` remains enabled for Phase 3B sources;
- `waiting_on_others` is enabled for `compliance`, `refunds`, and `logistics`;
- Home uses a maximum of three rows per bucket.

No `.env` edit is required. Explicit configuration overrides remain available for tests and defensive rollback. These settings control presentation only and do not broaden domain authorization.

Deploy in this order:

1. Apply the refund migration that adds indexed nullable `recovery_assigned_at` columns to `order_refunds` and `pos_refunds`.
2. Run the report-only assignment-gap command:

   ```powershell
   php artisan shop-owner:report-phase-3c-refund-assignment-gaps
   ```

3. Review each reported shop ID. Do not infer assignment age from `updated_at`, retry timestamps, or failure timestamps, and do not run an automatic backfill. Correct legitimate current rows through the authoritative recovery service or keep the affected rows operationally blocked until they are dispositioned.
4. Deploy the Action Center code and verify the focused backend/frontend gates.
5. Verify a healthy zero, partial, unavailable, and disabled Waiting state before expanding any future source coverage.

The report command is read-only. It emits stable shop and row IDs with counts and never emits customer, payment, refund-reason, or other business-record contents.

## State meanings

| State | Meaning | Owner-facing behavior |
| --- | --- | --- |
| Disabled | The Waiting bucket or one of its configured coverages is intentionally off. | The bucket/source is absent or normalized to the supported default; existing decision and exception behavior remains unchanged. |
| Healthy zero | Enabled adapters completed successfully and found no qualifying rows. | Show a usable empty state with a zero count. This is not an unavailable state. |
| Partial | At least one enabled adapter is healthy and another failed. | Keep healthy rows and counts, identify the unavailable source inline, and do not present the result as complete. |
| Unavailable | Every enabled adapter failed. | Show `Action Center currently unavailable`; never represent the failure as zero work. |
| Unsupported | The requested bucket/source is outside the bounded contract. | Reject or normalize the input; do not render an invented zero-value source. |

The full queue accepts only these Waiting filters: `all`, `compliance`, `refunds`, and `logistics`. There are no approval, exception, payroll, inventory, or generic task filters in Phase 3C.

## Rollback

To roll back only the Phase 3C presentation, disable the Waiting bucket:

```php
config([
    'owner_action_center.buckets.waiting_on_others.enabled' => false,
]);
```

For a deployed configuration change, update the committed application configuration through the normal release process and clear/rebuild the Laravel configuration cache as appropriate. Keep the migration and authoritative refund evidence intact. Do not delete columns, revert domain rows, remove routes, or redirect the existing Action Center to a different workflow.

Rollback guarantees:

- Phase 3A decision summaries and Phase 3B exception summaries remain available according to their own configuration;
- canonical Home and Action Center routes remain registered;
- canonical domain destinations and authorization do not change;
- no Action Center state is rolled back because none is persisted;
- source records are not mutated by disabling the bucket.

## Source readiness and evidence

| Capability | Entry/responsibility evidence | Query/health evidence | Migration status |
| --- | --- | --- | --- |
| Compliance renewal review | Current verified predecessor, material renewal window, exactly one owner-scoped pending successor, `super_admin` responsibility | Lifecycle contradictions fail health; validity/window policy is reused; bounded query test passes | Complete for the implemented scope |
| Order refund recovery | Failed, in-progress recovery with valid `finance`/`payment_recovery` party and `recovery_assigned_at` | Missing, pre-failure, future, or contradictory assignment evidence fails health; tenant/query tests pass | New writes complete; legacy rows with missing assignment evidence require report disposition |
| Repair refund recovery | Same recovery contract scoped to repair POS refunds | Independent failure isolation and bounded query tests pass | New writes complete; legacy rows with missing assignment evidence require report disposition |
| Active logistics recovery | Material failed leg, owner action not required, current rider/dispatcher projection, active non-exhausted recovery path | `LogisticsResponsibilityProjection` is reused; invalid assignment/batch state fails health; reassignment/terminal/query tests pass | Complete for the implemented scope |

An unresolved missing destination or missing authoritative action prevents a capability from being marked migration-complete. Phase 3C does not weaken middleware or invent an owner-safe route to make a source appear complete.

## Verification evidence

The implementation evidence is recorded by the following focused checks:

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter tests/Unit/Services/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php --compact
```

This gate covers the frozen contracts, all four Waiting adapters, classification precedence, tenant/privacy boundaries, partial/unavailable behavior, independent pagination, lifecycle assignment timestamps, and bounded query assertions.

The fresh local result was `185 passed (1,031 assertions)` in `33.90s`.

Frontend evidence:

- The focused direct Vitest run for `ActionCenter.tsx` and `DashboardCanonicalHome.test.tsx` passed: 2 files and 15 tests.
- The full direct Vitest run passed 112 files and 633 tests, with one failure in the unchanged `resources/js/Pages/ERP/Logistics/__tests__/Riders.test.tsx` filter-request assertion. Running that file alone reproduced the same failure, so it is recorded as an existing unrelated frontend baseline failure rather than changed by Phase 3C.
- The direct Vite production build passed after transforming 3,710 modules. Generated `public/build` output was restored and is not part of the Phase 3C commit.

Browser verification was attempted with the local Laravel server and Playwright, but no authenticated Shop Owner fixture was available: the local flow reached `/login`. Therefore the authenticated owner browser contract is not claimed here; the focused component evidence above is the available UI evidence.

## Ownership boundaries after Phase 3C

- Phase 3C owns discovery and classification only. Domain pages remain the mutation surface.
- Phase 4 owns remaining approval families and any additional owner-decision coverage.
- Phase 5 owns final navigation consolidation and ERP compatibility retirement criteria.
- Notifications, audit records, and domain recovery services remain authoritative outside the Action Center.
