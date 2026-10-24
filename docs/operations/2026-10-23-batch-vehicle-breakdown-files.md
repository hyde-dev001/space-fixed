# Batch vehicle breakdown changed files

Worktree: `.worktrees/failed-delivery-recovery`; branch `codex/failed-delivery-recovery`; unchanged HEAD `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`. Nothing is staged or committed. Root/OCR HEAD, status, index and before-edit hashes are preserved.

This list is the batch extension delta relative to its Phase 0 snapshot, including edits to earlier uncommitted recovery files. It supplements the [existing recovery inventory](2026-10-23-failed-delivery-recovery-files.md); it does not discard those prerequisites. See the [execution report](2026-10-23-batch-vehicle-breakdown-execution.md) for exact tests, limits and migration/release requirements.

Batch non-build paths: **59**. Combined recovery/batch non-build working-tree paths: **113**. Detailed per-file SHA-256 and preservation results are in ignored `output/failed-delivery-recovery/batch-interruption/final-hygiene.json`.

## Backend

- `app/Http/Controllers/Api/Logistics/DeliveryIncidentController.php`
- `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- `app/Http/Requests/Logistics/ReportBatchInterruptionRequest.php` (new)
- `app/Http/Requests/Logistics/ResolveBatchInterruptionRequest.php` (new)
- `app/Models/Logistics/DeliveryBatch.php`
- `app/Models/Logistics/DeliveryIncident.php`
- `app/Models/Logistics/DeliveryIncidentStop.php` (new)
- `app/Models/Logistics/ShipmentLeg.php`
- `app/Services/Logistics/ArrivalService.php`
- `app/Services/Logistics/AssignmentService.php`
- `app/Services/Logistics/BatchDispatchService.php`
- `app/Services/Logistics/BatchInterruptionService.php` (new)
- `app/Services/Logistics/DeliveryStartEligibility.php`
- `app/Services/Logistics/LogisticsActorPolicy.php`
- `app/Services/Logistics/LogisticsNotificationService.php`
- `app/Services/Logistics/ProofService.php`
- `app/Services/Logistics/RiderActiveWorkGuard.php`
- `app/Services/Logistics/ShipmentCustodyService.php`
- `app/Services/Logistics/ShipmentLegService.php`

## Additive database changes

- `database/migrations/2026_10_23_132125_add_batch_scope_to_delivery_incidents.php` (new)
- `database/migrations/2026_10_23_132200_add_batch_incident_holds_and_stops.php` (new)

## Frontend and frontend tests

- `resources/js/Pages/ERP/Logistics/Batches.tsx`
- `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/BatchInterruptionDialog.test.tsx` (new)
- `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- `resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx`
- `resources/js/Pages/ERP/Logistics/components/BatchHistoryModal.tsx`
- `resources/js/Pages/ERP/Logistics/components/BatchInterruptionDialog.tsx` (new)
- `resources/js/Pages/ERP/Logistics/components/BatchTable.tsx`
- `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- `resources/js/Pages/ERP/Logistics/components/CustodyHandoffs.tsx`
- `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts`
- `resources/js/services/logisticsApi.ts`
- `resources/js/types/logistics.ts`

## Routes

- `routes/web.php`

## Backend tests and guarded local verification tools

- `tests/Browser/failed_delivery_batch.py` (new)
- `tests/Browser/failed_delivery_batch_concurrency.py` (new)
- `tests/Browser/failed_delivery_batch_deployment_check.php` (new)
- `tests/Browser/failed_delivery_batch_records.php` (new)
- `tests/Browser/failed_delivery_batch_worker.php` (new)
- `tests/Browser/failed_delivery_environment.php`
- `tests/Browser/failed_delivery_fixture.php`
- `tests/Browser/failed_delivery_recovery.py`
- `tests/Feature/Logistics/BatchInterruptionNotificationTest.php` (new)
- `tests/Feature/Logistics/BatchInterruptionRecoveryTest.php` (new)
- `tests/Feature/Logistics/BatchInterruptionTest.php` (new)
- `tests/Feature/Logistics/LogisticsApiTest.php`
- `tests/Feature/Logistics/RetryResolutionTest.php`
- `tests/Support/BatchInterruptionFixture.php` (new)

## Documentation

- `docs/ai-learning-log.md`
- `docs/operations/2026-10-23-batch-vehicle-breakdown-execution.md` (new)
- `docs/operations/2026-10-23-batch-vehicle-breakdown-files.md` (new)
- `docs/operations/2026-10-23-failed-delivery-recovery-files.md`
- `docs/operations/failed-delivery-recovery-manual-testing.md`
- `docs/operations/failed-delivery-recovery.md`
- `docs/superpowers/plans/2026-10-23-batch-vehicle-breakdown-recovery.md`

## Generated build and protected areas

The final build has **404 manifest entries / 405 referenced asset files**; imports and files resolve. Manifest SHA-256: `060b9670e2fca57f341a4c0b94abfa646e43635f0be5fafae56304a877605532`. Git reports 308 tracked build path changes and 310 new hashed build assets relative to HEAD. This worktree build is local and uncommitted.

Existing `.env`, package/lockfiles, role seeder and route catalog were not changed by the batch extension. All 24 tracked `public/ocr/` assets remain intact and pass the versioned integrity check. Existing five recovery migrations are preserved; the two new batch migrations follow them and refuse populated destructive reversal.

Disposable schemas, private synthetic proof files, browser screenshots, test logs, helper configs and local hash inventories stay under ignored output. They are not a deployment package. No production database, dependency, global Git configuration, push, merge, rebase or deployment was modified.

Latest follow-up inventory and verification: [October 24 release readiness](2026-10-24-logistics-release-readiness.md). Counts and build hashes above describe the earlier checkpoint; the follow-up preserves those changes and records its own delta.
