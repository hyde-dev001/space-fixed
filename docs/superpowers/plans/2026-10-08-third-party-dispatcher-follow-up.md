# Dispatcher and Staff shipping classification follow-up

Worktree/branch: `.worktrees/qa-four-follow-up`, `fix/qa-four-follow-up`. One sequential agent.

## Confirmed findings

Production read-only output verified the earlier PHP guard fix was deployed. The default log channel was single through stack, with LOG_LEVEL=error; controller warnings were therefore filtered. The reported dispatcher had assign/batch capabilities and accessible Logistics. The affected pending leg's Order was explicitly third_party, carrier Lalamove. The server correctly denied internal dispatch, but the UI advertised scheduling/assignment and included the leg in batch choices. Its empty 403 falsely looked like a permission regression.

User clarified that Staff's Shipped tab was involved and shop-owned shipping was intended. A separate regression proved that changing Shipping Business in Staff/Owner forms could retain the previous non-null delivery_method. The shared shipping service now derives the method from an explicitly submitted carrier when the form omits an explicit method. Explicit submitted methods remain authoritative. Selecting shop-owned after Lalamove now persists shop_owned and allows dispatcher scheduling; selecting Lalamove after shop-owned persists third_party.

The existing production record had both third_party and Lalamove, so it cannot be safely converted through a blanket migration or permission bypass. It was not modified. Match the exact Staff order to the delivery before correcting historical business data. No production database or configuration writes were performed.

## Contract and workflow

- Keep third-party retail tracking visible, with courier-specific feedback and no internal scheduling/assignment controls.
- Exclude third-party retail deliveries from scheduled/unscheduled batch pools and nearest-stop suggestions. Legacy carrier-only classification uses the existing Order resolver.
- Preserve shop-owned dispatch, seeded dispatcher/rider role access, assigned-rider acceptance, tenant/state/module/attendance and maintenance checks.
- Keep Staff Shipped rows explicit about the saved Shipping Business; show Shop-owned logistics or Third-party courier plus carrier name.
- Keep warning severity unchanged; denied controller policy checks return code LOGISTICS_ACTION_DENIED, a safe reason category and a nonblank message. Foreign-shop denials remain generic and disclose no customer or tenant data.

- [x] Reproduce third-party pool/control and blank-response failures; cover seeded dispatcher/rider roles with no direct dispatcher grants.
- [x] Reuse Order::resolvedDeliveryMethod for batched pool/suggestion filtering and existing order summaries for UI classification. Keep server mutation protection.
- [x] Reproduce and fix stale method persistence when Staff changes carriers; add Staff Shipped carrier labels.
- [x] Run focused and broader logistics/shipping tests, browser QA, fresh build, syntax/formatting/diff checks.
- [x] Sequential Standards, Spec, risk, reuse/dead-code review; record results and prepare commit/push on the same feature branch.

## Expected files

- `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`
- `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- `app/Services/Logistics/BatchSuggestionService.php`
- `app/Services/Logistics/LogisticsActorPolicy.php`
- `app/Services/Logistics/LogisticsMovementEligibility.php`
- `app/Services/Orders/OrderFulfillmentService.php`
- `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- `resources/js/types/logistics.ts`
- `tests/Feature/Logistics/BatchSuggestionServiceTest.php`
- `tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php`
- `tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php`
- `tests/Feature/Logistics/StaffRetailShippingCoverageTest.php`
- This plan, `docs/ai-learning-log.md`, and fresh generated `public/build`.

## Verification evidence

Red regressions captured blank policy-denial responses, third-party records in batch choices/suggestions, third-party assignment controls, missing Staff Shipped carrier text, and both stale-method directions. Seeded dispatcher/rider roles with no direct dispatcher grants passed scheduling, draft/offer, standalone assignment and both acceptances before the UI fix; permission expansion was not needed.

Focused backend:

```powershell
php -d extension=gd artisan test tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php tests/Feature/Logistics/BatchSuggestionServiceTest.php
```

26 tests, 172 assertions, no failures, exit 0. A synthetic test initially tried mass-assigning guarded carrier fields and asserted timestamp ordering; corrected fixture persistence and identity-based matching, then removed temporary debug output.

Broader backend:

```powershell
php -d extension=gd artisan test tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php tests/Feature/Logistics/BatchSuggestionServiceTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php tests/Feature/Logistics/RiderTenantAuthorizationTest.php tests/Feature/Logistics/ShopOwnerLogisticsResponsibilityTest.php tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php tests/Feature/Logistics/RiderProgressionTest.php tests/Unit/Services/Logistics/LogisticsActorPolicyTest.php tests/Feature/Maintenance/MaintenanceEnforcementTest.php tests/Feature/Orders/OrderFulfillmentPolicyTest.php tests/Unit/Services/Orders/OrderTransitionPolicyTest.php tests/Feature/RetailWarranty/OfficialFulfillmentTest.php
```

248 tests, 973 assertions, no failures, exit 0. Warning labels are caused by the absent worktree .env; none was created or edited.

Broader frontend:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/Logistics resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/providers/__tests__/MaintenanceProvider.test.tsx --maxWorkers=2
```

12 files, 255 tests passed. Existing Node local-storage warnings remain. Visual QA found the unchanged Waiting for rider placeholder still misleading for external tracking; a two-case red test reproduced it, then the placeholder was changed to Waiting for courier update. The final Shipments suite was rerun after that change (record final result below).

`php -l` passed on all 11 changed PHP files. `vendor/bin/pint --test` passed for the three modified regression test files plus LogisticsMovementEligibility and LogisticsActorPolicy. `git diff --check` passed. No frontend lint/type-check configuration exists, so neither is claimed. Unrelated full suites were not rerun.

Isolated Chromium/MariaDB browser QA with both user/owner guards active: pool displayed only two internal orders; selecting them saved a draft (schedule 200, create 201); Lalamove remained readable in Shipments with no internal rider picker or schedule/assign button, desktop and mobile; Staff Shipped displayed both Shop-owned logistics and Third-party courier · Lalamove. No uncaught page errors. Screenshots visually reviewed. Test command: `python -X utf8 storage/framework/cache/third-party-dispatcher-browser.py`; test helper/logs/screenshots are ignored, not committed. Final fresh-build browser check and build result are recorded below.

## Sequential review record

| Gate | Result |
| --- | --- |
| Simplify / ponytail | Pass: existing resolver/policy/services/components; batched Order lookup, no new dependencies or unrelated architecture. |
| Standards -> Spec -> correctness | Pass: root UI/backend classification agreement and shipping carrier/method save boundary covered; historical third-party data not silently reinterpreted. |
| Clean TypeScript / React | Pass: two optional typed summary fields, pure boolean/status condition and existing carrier fields; no new effects, state, any or unchecked casts. |
| Karpathy | Pass: production facts distinguished from reproduced stale-method bug; focused edits and runnable checks. |
| Code splitting | N/A: no new imports/heavy frontend code; existing chunking retained. |
| Gauge improvements | Pass: pre-fix red behaviors green afterward. Performance not measured; filtering uses one batched tenant-scoped Order query instead of per-leg reads. |
| Security / Laravel | Pass: no bypass, role mutation or public data; backend still blocks third-party work. Generic foreign-tenant denial. Shipping persists within existing locked transaction and retains coverage/module/state guards. |
| Verification before completion | Pass: fresh targeted/broader tests, syntax/formatting/build/diff and browser evidence. |
| Reuse / dead code | Pass: shared existing service method used by pools and suggestions, existing summary query, existing frontend controls and carrier state; no orphan imports or debug output. |
| Writing / durable learning | Pass: this plan and shared shipping/classification lesson. |

Delivery: commit and push `fix/qa-four-follow-up`, including final `public/build`; user merges/deploys to solespace-b. No merge/deploy or historical-data conversion performed by the agent.
Final revision checks: Shipments suite `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --maxWorkers=2` passed all 64 tests. `pnpm run build` passed in 31.13s after the placeholder edit. Final browser command `python -X utf8 storage/framework/cache/third-party-dispatcher-browser-final.py` passed desktop/mobile courier wording, absence of internal controls, Staff carrier labels and no uncaught page errors on that final build. Manifest has 397 entries; referenced file/CSS assets exist and no source entry from HEAD disappeared. Isolated QA services stopped afterward. Source/test/build/docs included together in the follow-up commit; ignored fixtures, logs, screenshots and local services excluded.
