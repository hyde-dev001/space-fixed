# Customer Report Evidence and Refund Routing Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with checkpoints.

**Goal:** Require five images and one parcel-opening video for Shop-owned REPORT ORDER disputes, expose the evidence to the dispatcher, and carry it into staff refund approval while preserving the existing third-party refund/return flow.

**Architecture:** Keep delivery disputes and legacy refunds as separate domain records. Store new customer dispute media on the private local disk and persist safe metadata on delivery_disputes; expose media through a tenant-authorized logistics endpoint. When refund_required creates an OrderRefund, staff payloads will link back to the resolved dispute evidence instead of changing the legacy refund evidence contract.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia, React 18, TypeScript 5.7, Vitest, PHPUnit, Laravel local storage disk.

---

### Task 1: Lock the API and storage contract with failing tests

**Files:**
- Create: tests/Feature/CustomerDeliveryDisputeEvidenceTest.php
- Modify: tests/Feature/CustomerDeliveryReceiptTest.php
- Test support: existing factories and Storage::fake('local')

- [ ] **Step 1: Write the failing customer report evidence tests**

Cover these behaviors:

- a Shop-owned customer report accepts exactly five fake images and one fake video;
- a report without media, with the wrong count, or with an invalid media type is rejected;
- an oversized image and oversized video are rejected;
- a valid report stores six private evidence records and keeps the order delivered/disputed;
- a Third-party order cannot enter the dispatcher report route;
- a duplicate active report does not create another dispute or duplicate files.

- [ ] **Step 2: Write the failing authorization and staff handoff tests**

Cover these behaviors:

- a same-shop dispatcher can fetch dispute evidence;
- a dispatcher from another shop and a customer cannot fetch it;
- resolving an investigated dispute as refund_required links the refund to the dispute;
- the staff orders payload exposes the linked customer evidence with image/video metadata;
- the existing third-party refund payload remains unchanged.

- [ ] **Step 3: Run the focused tests and verify the expected failures**

Run:

~~~powershell
& { $env:APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/CustomerDeliveryDisputeEvidenceTest.php tests/Feature/CustomerDeliveryReceiptTest.php --compact; Write-Output "PHPUNIT_EXIT=$LASTEXITCODE" }
~~~

Expected: failures because the dispute endpoint does not accept media, the evidence column/endpoint is absent, and staff payloads do not expose linked evidence.

- [ ] **Step 4: Commit the red tests**

~~~powershell
git add -- tests/Feature/CustomerDeliveryDisputeEvidenceTest.php tests/Feature/CustomerDeliveryReceiptTest.php
git commit -m "test: define customer dispute evidence flow"
~~~

### Task 2: Add private dispute evidence persistence and validation

**Files:**
- Create: database/migrations/2026_08_23_000001_add_evidence_media_to_delivery_disputes_table.php
- Create: app/Services/DeliveryDisputeEvidenceService.php
- Modify: app/Models/DeliveryDispute.php
- Modify: app/Services/DeliveryDisputeService.php
- Modify: app/Http/Controllers/UserSide/OrderController.php:reportDeliveryIssue

- [ ] **Step 1: Add the JSON evidence metadata column and model cast**

Add nullable evidence_media JSON storage to delivery_disputes. Cast it to an array and allow it through the model's existing fillable contract. Each entry stores only a generated media ID, private local path, MIME type, media kind, and original filename.

- [ ] **Step 2: Implement the minimal evidence storage service**

Follow the existing DeliveryIncidentController private-evidence pattern:

- store files under delivery-dispute-evidence/order-{orderId} on the local disk;
- generate stable opaque media IDs;
- preserve MIME type and original filename for UI rendering;
- delete files already stored if a later file or persistence step fails;
- reject unsafe paths when resolving evidence.

- [ ] **Step 3: Extend DeliveryDisputeService::report to persist evidence**

Require six validated evidence entries for a report, keep the existing ownership/deadline/refund/duplicate guards, and persist the evidence atomically with the dispute. Keep order.status unchanged and continue setting customer_receipt_status to disputed.

- [ ] **Step 4: Extend the customer controller validation**

Accept multipart media with exactly six files and the existing refund extensions/MIME types. Repeat the 5-image/1-video and 20 MB/256 MB limits server-side, ensure the order is Shop-owned, store evidence through the service, and clean up uncommitted files on exceptions.

- [ ] **Step 5: Run the focused backend tests and verify they pass**

Run the command from Task 1. Expected: all customer evidence and existing receipt/dispute tests pass.

- [ ] **Step 6: Commit the backend evidence foundation**

~~~powershell
git add -- database/migrations/2026_08_23_000001_add_evidence_media_to_delivery_disputes_table.php app/Services/DeliveryDisputeEvidenceService.php app/Models/DeliveryDispute.php app/Services/DeliveryDisputeService.php app/Http/Controllers/UserSide/OrderController.php
git commit -m "feat: persist customer delivery dispute evidence"
~~~

### Task 3: Add authorized evidence viewing and payload handoff

**Files:**
- Create: app/Http/Controllers/Api/Logistics/DeliveryDisputeEvidenceController.php
- Modify: routes/web.php in the authenticated logistics group
- Modify: app/Http/Controllers/Logistics/ErpLogisticsController.php
- Modify: app/Http/Controllers/Api/StaffOrderController.php
- Modify: app/Models/OrderRefund.php if a relation is needed for linked disputes

- [ ] **Step 1: Write the failing evidence URL/payload assertions**

Assert that dispatcher and staff payloads return safe evidence URLs and kind/mime_type metadata, never private paths. Assert that the evidence endpoint returns the correct image/video response only for an authorized same-shop actor.

- [ ] **Step 2: Implement the tenant-authorized evidence endpoint**

Add a route such as GET /api/logistics/delivery-disputes/{dispute}/evidence/{mediaId}. Authorize same-shop dispatchers with logistics permissions and same-shop staff with access-staff-job-orders; reject customers and other shops. Serve files from the local disk with private/no-store headers and X-Content-Type-Options: nosniff.

- [ ] **Step 3: Add dispatcher dispute evidence to shipment summaries**

Extend customer_disputes with safe evidence entries. Evidence must be visible while the dispute is open or investigating; the existing Start investigation gate for resolution remains unchanged.

- [ ] **Step 4: Add linked evidence to the staff refund payload**

When refund_required links the dispute to the generated OrderRefund, expose the dispute evidence as a separate customer_dispute_evidence field in staff order index/show payloads. Do not change the existing latest_refund.evidence_media shape used by third-party refund requests.

- [ ] **Step 5: Run backend tests and commit**

Run:

~~~powershell
& { $env:APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/CustomerDeliveryDisputeEvidenceTest.php tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/CustomerDeliveryReceiptTest.php --compact; Write-Output "PHPUNIT_EXIT=$LASTEXITCODE" }
~~~

Expected: all focused tests pass, including existing private logistics evidence authorization tests.

~~~powershell
git add -- app/Http/Controllers/Api/Logistics/DeliveryDisputeEvidenceController.php routes/web.php app/Http/Controllers/Logistics/ErpLogisticsController.php app/Http/Controllers/Api/StaffOrderController.php app/Models/OrderRefund.php tests/Feature/CustomerDeliveryDisputeEvidenceTest.php tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Logistics/LogisticsApiTest.php
git commit -m "feat: expose dispute evidence to logistics and staff"
~~~

### Task 4: Add carrier-specific customer actions and report uploader

**Files:**
- Modify: resources/js/Pages/UserSide/Orders/MyOrders.tsx
- Modify: resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx
- Modify: app/Services/DeliveryDisputeService.php if the Shop-owned guard is not already covered
- Modify: app/Http/Controllers/UserSide/OrderController.php request-refund guard if direct Shop-owned refund requests are still accepted
- Test: tests/Feature/CustomerDeliveryDisputeEvidenceTest.php

- [ ] **Step 1: Write failing frontend tests for action routing and media validation**

Cover:

- Shop-owned orders show REPORT ORDER and not the direct REFUND action;
- Third-party orders keep the existing REFUND action;
- the report modal rejects incomplete media and accepts exactly five images plus one video;
- report submission sends multipart reason, notes, and media[].

- [ ] **Step 2: Add the report modal state and reuse existing media rules**

Replace the current reason-only report prompt with a controlled report modal using the existing accepted extensions, size limits, file previews, remove controls, and exact 5-image/1-video readiness check. Keep the existing refund modal behavior and third-party request endpoint unchanged.

- [ ] **Step 3: Route buttons by logistics type**

Use the server's is_shop_owned_delivery/carrier flag to show the dispatcher report path only for Shop-owned orders. Hide direct refund for Shop-owned orders and retain the current direct refund button for Third-party orders. Keep backend duplicate guards authoritative.

- [ ] **Step 4: Run focused frontend tests and commit**

Run:

~~~powershell
& 'C:\xampp\htdocs\solespace-master\node_modules\.bin\vitest.cmd' run resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx --reporter=dot --pool=threads --no-file-parallelism --maxWorkers=1 --minWorkers=1
~~~

Expected: all focused My Orders tests pass.

~~~powershell
git add -- resources/js/Pages/UserSide/Orders/MyOrders.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx app/Services/DeliveryDisputeService.php app/Http/Controllers/UserSide/OrderController.php tests/Feature/CustomerDeliveryDisputeEvidenceTest.php
git commit -m "feat: route customer actions by logistics type"
~~~

### Task 5: Render evidence for dispatcher and staff reviewers

**Files:**
- Modify: resources/js/Pages/ERP/Logistics/Shipments.tsx
- Modify: resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
- Modify: resources/js/Pages/ERP/STAFF/JobOrders.tsx
- Modify: resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts

- [ ] **Step 1: Write failing UI assertions**

Assert that:

- dispatcher dispute cards render image evidence and a playable video before investigation;
- the resolve controls remain hidden until Start investigation is completed;
- staff refund review renders customer dispute evidence after refund_required;
- existing third-party refund evidence still renders.

- [ ] **Step 2: Add shared evidence rendering behavior in each existing page**

Keep the existing modal conventions. Render images as thumbnails/preview links and videos with a native video controls element or the page's existing media modal. Do not expose raw storage paths.

- [ ] **Step 3: Run focused logistics/staff frontend tests and commit**

Run:

~~~powershell
& 'C:\xampp\htdocs\solespace-master\node_modules\.bin\vitest.cmd' run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts --reporter=dot --pool=threads --no-file-parallelism --maxWorkers=1 --minWorkers=1
~~~

Expected: all focused UI tests pass.

~~~powershell
git add -- resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
git commit -m "feat: show dispute evidence in review pages"
~~~

### Task 6: Full verification, fresh build, and handoff

**Files:**
- Modify: public/build/** (generated fresh production assets)
- No third-party delivery/return source files should change outside the explicitly tested carrier guards.

- [ ] **Step 1: Run all focused backend tests**

Run the new evidence tests, customer receipt tests, staff payload tests, logistics API tests, refund workflow tests, and existing third-party return tests. Expected: exit 0.

- [ ] **Step 2: Run the complete frontend suite**

Run the repository Vitest binary with the constrained worker flags. Expected: all test files and tests pass.

- [ ] **Step 3: Run PHP lint and diff hygiene checks**

Run php -l for every changed PHP file and git diff --check. Expected: no syntax or whitespace errors.

- [ ] **Step 4: Generate a fresh public build**

Run:

~~~powershell
& 'C:\xampp\htdocs\solespace-master\node_modules\.bin\vite.cmd' build --logLevel error
Write-Output "VITE_EXIT=$LASTEXITCODE"
~~~

Expected: VITE_EXIT=0; retain generated public/build artifacts for the deployment preview.

- [ ] **Step 5: Review the complete diff**

Confirm the only source changes are the approved report evidence, carrier routing, dispatcher/staff display, tests, design/plan docs, and generated build. Confirm no third-party return implementation was modified unintentionally.

- [ ] **Step 6: Commit the fresh build and final changes**

~~~powershell
git add -- public/build
git commit -m "build: refresh public assets for customer dispute evidence"
~~~

- [ ] **Step 7: Push the feature branch after verification**

~~~powershell
git push origin feat/customer-delivery-receipt-dispute
~~~

Verify the remote hash matches local HEAD and report the exact branch/commit and test results.

