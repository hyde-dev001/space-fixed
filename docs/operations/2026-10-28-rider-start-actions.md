# Rider delivery actions after starting

## Report and acceptance

The hosted Rider page continued to show **Start delivery** after acceptance and
start, hiding **I've arrived** and **Report incident**. Repeating Start did not
advance the page. The screenshot also showed a low-accuracy GPS warning.

Acceptance: recognize a recorded start for the current accepted assignment in
standalone and batch work, retain that recognition after the appointment expires,
and keep old-assignment events from authorizing a new offer. A tracking warning
must not replace the delivery actions. No historical activation is fabricated.

## Root cause and bounded change

`ErpLogisticsController::deliveries` eagerly loaded only pickup/dropoff arrival
events. `DeliveryStartEligibility::hasActivated` uses the loaded event collection
when available, so the page omitted the existing `in_transit`/`out_for_delivery`
activation evidence and incorrectly projected `activation_required: true`.
The start endpoint correctly recognized the persisted start and returned without
duplicating it, explaining the apparently ineffective repeated action.

Both standalone and batch Rider event queries now include the two activation
event types. Existing assignment/time checks, attendance, custody, module,
appointment and proof authorization remain authoritative. Events are still removed
from the public page payload. There is no schema, frontend application, dependency,
asset or production data change.

File-level execution: reproduce through `PostWindowDeliveryProgressionTest`;
extend the two controller eager loads; verify old-assignment denial in
`RiderMyDeliveriesPageTest` and GPS/action coexistence in
`MyDeliveriesGpsTracking.test.tsx`; run focused checks and review sequentially.

## Verification on disposable local data

No cached `bootstrap/cache/config.php` was present. PHPUnit used
`APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`.

- Before the fix, all five real accept/start/page journeys failed because
  `activation_required` remained true: normal, same-Rider retry, replacement Rider,
  detached stop and batch stop (5 tests / 21 assertions / 5 failures).
- After the fix, `php -d extension=gd vendor/phpunit/phpunit/phpunit
  tests/Feature/Logistics/RiderMyDeliveriesPageTest.php
  tests/Feature/Logistics/PostWindowDeliveryProgressionTest.php
  tests/Feature/Logistics/RecoveryActivationTest.php
  tests/Feature/Logistics/RetryResolutionTest.php
  tests/Feature/Logistics/DeliveryArrivalTest.php --no-progress --colors=never
  --do-not-cache-result --log-junit output/rider-start-projection-regression.xml`
  exited 0: **48 tests / 353 assertions**. This includes page refresh before/after
  expiry, arrival, corrected proof, review and completion, plus old-assignment
  activation denial for standalone and batch work.
- Installed Vitest runner with `run
  resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
  resources/js/Pages/ERP/Logistics/__tests__/MyDeliveriesGpsTracking.test.tsx
  --reporter=json --outputFile=output/rider-start-projection-frontend.json`
  exited 0: **59 tests passed** across two files. The real tracker receives a
  543,800 m browser accuracy reading while arrival/incident buttons remain enabled.
  An initial run failed because the tracker integration fixture did not mock its
  fallback location helper/watch callback; the fixture was corrected. The default
  sandbox initially denied dependency reads; the approved runner completed without
  installing packages. `pnpm` is unavailable in this environment.
- `php -l app/Http/Controllers/Logistics/ErpLogisticsController.php` and
  `git diff --check` passed.

Sequential review: simplicity, repository Standards, acceptance and correctness
pass; existing eager loads and activation helpers are reused. Security review
confirms no authorization weakening or event metadata exposure. No abandoned
imports/branches were introduced. Production TS/bundle review and a fresh build
are N/A because only PHP application code changed. Query count/latency was not
measured; the existing eager-load queries are retained with two added event types.
No new cross-project learning rule is needed.

This establishes the local code defect and regression fix, not verification of the
currently deployed host. Hosted browser retesting, deployment and production data
repair were not performed. Existing unrelated attendance changes are preserved.
