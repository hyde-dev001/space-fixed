# Failed-delivery recovery changed files

All changes belong to `.worktrees/failed-delivery-recovery`, branch `codex/failed-delivery-recovery`. Nothing is staged or committed. Root and OCR workspace status/HEAD/staged snapshots are unchanged.

The approved audit and plan are unchanged copies from the OCR worktree. See the [verification-gap follow-up](2026-10-23-failed-delivery-verification-gaps.md) for the latest MFA, seeders, GD, scheduler and migration evidence.

The subsequent approved batch-interruption extension is recorded in the [batch file inventory](2026-10-23-batch-vehicle-breakdown-files.md) and [batch verification report](2026-10-23-batch-vehicle-breakdown-execution.md). Its fresh build supersedes the earlier manifest counts/hash below; those older numbers describe the pre-batch verification only. All prior recovery changes remain present and unstaged.

## Backend

- `app/Console/Commands/DeliverPendingLogisticsNotifications.php` (new)
- `app/Console/Commands/MonitorOverdueDeliveries.php`
- `app/Http/Controllers/Api/Logistics/DeliveryIncidentController.php`
- `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- `app/Http/Controllers/Api/Logistics/ShipmentCustodyController.php` (new)
- `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- `app/Http/Requests/Logistics/ResolveDeliveryRetryRequest.php` (new)
- `app/Models/Logistics/DeliveryAssignment.php`
- `app/Models/Logistics/DeliveryEvent.php`
- `app/Models/Logistics/DeliveryIncident.php`
- `app/Models/Logistics/HandoffProof.php`
- `app/Models/Logistics/ShipmentCustodyTransfer.php` (new)
- `app/Models/Logistics/ShipmentLeg.php`
- `app/Models/Notification.php`
- `app/Services/CodCollectionService.php`
- `app/Services/Logistics/ArrivalService.php`
- `app/Services/Logistics/AssignmentService.php`
- `app/Services/Logistics/BatchDispatchService.php`
- `app/Services/Logistics/DeliveryEventService.php`
- `app/Services/Logistics/DeliveryIncidentService.php`
- `app/Services/Logistics/DeliveryStartEligibility.php` (new)
- `app/Services/Logistics/LogisticsNotificationService.php`
- `app/Services/Logistics/ProofReviewService.php`
- `app/Services/Logistics/ProofService.php`
- `app/Services/Logistics/RiderActiveWorkGuard.php`
- `app/Services/Logistics/ShipmentCustodyService.php` (new)
- `app/Services/Logistics/ShipmentLegService.php`
- `app/Services/Logistics/SourceShipmentService.php`
- `app/Services/RepairDeliveryService.php`

## Configuration

- `config/shop_modules.php`

## Database

- `database/factories/Logistics/HandoffProofFactory.php`
- `database/migrations/2026_10_23_004848_add_recovery_snapshot_to_delivery_incidents.php` (new)
- `database/migrations/2026_10_23_005530_create_shipment_custody_transfers_table.php` (new)
- `database/migrations/2026_10_23_005531_add_confirmed_custody_to_shipment_legs_and_proofs.php` (new)
- `database/migrations/2026_10_23_010928_add_customer_appointments_and_retry_identity.php` (new)
- `database/migrations/2026_10_23_014614_add_logistics_notification_delivery_state.php` (new)
- `database/seeders/EmployeeSeeder.php`

## Frontend

- `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- `resources/js/Pages/ERP/Logistics/components/CustodyHandoffs.tsx` (new)
- `resources/js/Pages/ERP/Logistics/components/RetryResolutionDialog.tsx` (new)
- `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx`
- `resources/js/services/logisticsApi.ts`
- `resources/js/types/logistics.ts`

## Routes

- `routes/console.php`
- `routes/web.php`

## Tests and local verification tools

- `tests/Browser/failed_delivery_concurrency.py` (new)
- `tests/Browser/failed_delivery_deployment_check.php` (new)
- `tests/Browser/failed_delivery_environment.php` (new)
- `tests/Browser/failed_delivery_fixture.php` (new)
- `tests/Browser/failed_delivery_recovery.py` (new)
- `tests/Browser/failed_delivery_server.php` (new)
- `tests/Browser/failed_delivery_verify_records.php` (new)
- `tests/Browser/failed_delivery_worker.php` (new)
- `tests/Feature/Auth/EmployeeMfaLoginFlowTest.php` (new)
- `tests/Feature/Cod/CodCollectionTest.php`
- `tests/Feature/Logistics/CustomerTrackingTest.php`
- `tests/Feature/Logistics/DeliveryExecutionTest.php`
- `tests/Feature/Logistics/DeliveryIncidentServiceTest.php`
- `tests/Feature/Logistics/FailedDeliveryRecoveryTest.php` (new)
- `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`
- `tests/Feature/Logistics/LogisticsApiTest.php`
- `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- `tests/Feature/Logistics/LogisticsSeederTest.php`
- `tests/Feature/Logistics/RecoveryNotificationTest.php` (new)
- `tests/Feature/Logistics/RetailReturnFailureRecoveryTest.php` (new)
- `tests/Feature/Logistics/RetryResolutionTest.php` (new)
- `tests/Feature/Logistics/ShipmentCustodyTransferTest.php` (new)
- `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- `tests/Feature/Repair/RepairDeliveryReconciliationTest.php`
- `tests/Feature/Repair/RepairReturnRecoveryTest.php`

## Documentation

- `docs/ai-learning-log.md`
- `docs/operations/2026-10-23-failed-delivery-recovery-audit.md` (new)
- `docs/operations/2026-10-23-failed-delivery-recovery-execution.md` (new)
- `docs/operations/2026-10-23-failed-delivery-recovery-files.md` (new)
- `docs/operations/2026-10-23-failed-delivery-verification-gaps.md` (new)
- `docs/operations/failed-delivery-recovery.md` (new)
- `docs/operations/failed-delivery-recovery-manual-testing.md` (new)
- `docs/superpowers/plans/2026-10-23-failed-delivery-recovery.md` (new)

## Repository and generated output

- `.gitignore`: ignore only this feature output and local Python browser cache.
- `public/build/manifest.json` and fresh generated assets changed in this isolated worktree. No root build or OCR runtime file changed.

The fresh deterministic manifest has 403 entries and 404 referenced assets/imports; all resolve. SHA-256: `aeecf77b068985050617f80780551d28146bc3aab899593d714e0eb42d310a45`.

Git reports 308 tracked build path changes and 309 new build assets. Owned non-build paths: 83. Full per-file hashes are in `output/failed-delivery-recovery/changed-files.json`; follow-up baseline/delta checks are in `output/failed-delivery-recovery/verification-gaps/`. Logs, disposable databases and synthetic screenshots remain ignored and are not release artifacts.

Latest follow-up inventory and verification: [October 24 release readiness](2026-10-24-logistics-release-readiness.md). Counts and build hashes above describe the earlier checkpoint; the follow-up preserves those changes and records its own delta.
