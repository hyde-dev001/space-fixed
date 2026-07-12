# Shop-Owned Logistics Production Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the complete shop-owned workflow is concurrency-safe, observable, tenant-isolated, notification-deduplicated, and regression-clean before rollout.

**Architecture:** Add verification and operational read models only where a production-readiness assertion lacks evidence. Do not introduce forecasting, maps, live GPS, or a second event/notification pipeline.

**Tech Stack:** Laravel 12, PHPUnit, database transactions, queued notifications, Inertia React/TypeScript, Vitest

---

### Task 1: Cross-flow concurrency verification

**Files:** Create `tests/Feature/Logistics/LogisticsConcurrencyTest.php`.

- [ ] Add separate-connection/process tests for source creation, batching, offering, acceptance, pickup, out-for-delivery, proof approval, retry, incident resolution, return creation, and receipt.
- [ ] Run the file and record every duplicate/stale failure.
- [ ] Fix each root cause in the shared transition using stable lock order and existing-state detection.
- [ ] Re-run until every case passes.
- [ ] Commit: `test: verify logistics concurrency`.

### Task 2: Notification deduplication

**Files:** Modify existing delivery event/notification listeners; test `tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php`.

- [ ] Test one notification for initial estimate, material change, out-for-delivery, failed/rescheduled, delivered, cancellation, return resolution, rider offer/reassignment, and dispatcher alerts.
- [ ] Verify failure under repeated events/jobs.
- [ ] Reuse event identity plus recipient/type as the deduplication key.
- [ ] Verify and commit: `fix: deduplicate logistics notifications`.

### Task 3: Operational dashboard metrics

**Files:** Modify `ErpLogisticsController`, dashboard page/types; test `tests/Feature/Logistics/LogisticsOperationalMetricsTest.php` and a focused Vitest file.

- [ ] Test due today, overdue, failed attempts, unassigned, rider workload, and delivery success rate with tenant isolation.
- [ ] Verify failure.
- [ ] Add direct aggregate queries and existing UI cards; no forecasting or analytics service.
- [ ] Verify and commit: `feat: show logistics operational metrics`.

### Task 4: Audit and privacy review

**Files:** Create `tests/Feature/Logistics/LogisticsAuditPrivacyTest.php`; modify serializers only if failing.

- [ ] Assert every customer-impacting/destructive action has actor, reason, timestamp, tenant, and internal event.
- [ ] Assert customer payloads hide rider identity, phone, batch, responsibility, and dispatcher notes; accepted assigned riders alone may access phone and access is audited.
- [ ] Fix shared serializers/authorization and re-run.
- [ ] Commit: `test: verify logistics audit privacy`.

### Task 5: Production regression gate

- [ ] Run all logistics PHPUnit tests twice, once on SQLite and once on the production-like MySQL test connection.
- [ ] Run all logistics/customer tracking Vitest files.
- [ ] Run the complete project test suite, `npm run build`, `php artisan route:list`, and `git diff --check`.
- [ ] Exercise migrations up/down on a production-like snapshot and confirm no duplicate/invalid historical rows block rollout.
- [ ] Record commands, counts, environment, and results in `docs/superpowers/verification/shop-owned-logistics-production.md`.

### Task 6: Rollout checklist

- [ ] Confirm queue worker and overdue schedule configuration.
- [ ] Confirm storage visibility/retention for pickup, delivery, incident, and return proof photos.
- [ ] Confirm dispatcher/rider permissions after seeding.
- [ ] Confirm rollback procedure and monitoring owners.
- [ ] Do not enable shop-owned production dispatch until every prior checkbox has evidence.

