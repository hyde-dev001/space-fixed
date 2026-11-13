# Attendance Camera and Grace-Period Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require a private camera photo for employee self-service clock-ins, show it to same-shop HR inside the Attendance Receipt, and apply each shop's saved lateness grace period without changing payroll formulas.

**Architecture:** Add reversible columns for shop policy and per-attendance evidence/policy snapshots. Keep clock-in eligibility and payroll inputs in the existing Laravel attendance flow; serve images through a shop-scoped HR endpoint. Reuse the existing Time In page, settings form, and HR receipt modal.

> **Follow-up requirement:** The user later removed the prompt preference checkbox. Current behavior always shows the required-photo modal and requires an explicit **Open Camera** click. The original checkbox-related steps below record the approved implementation at the time; the preference is no longer read or written by the application.

**Tech Stack:** Laravel 12, Eloquent, Laravel local private filesystem, Inertia, React 18, TypeScript, Vitest, PHPUnit.

## Global Constraints

- Grace period is an integer from 0 through 120 minutes; 0 disables it.
- A check-in no more than the saved grace period after expected check-in is present with `is_late=false` and `minutes_late=0`.
- New attendance rows snapshot the grace policy; null legacy snapshots behave as 0 and are not reclassified after settings change.
- Employee self-service clock-in requires a JPEG, PNG, or WebP camera capture no larger than 5 MB; no gallery or file picker is offered.
- Photos stay on the private local disk; only authorized same-shop HR attendance viewers can retrieve them.
- The account-level "Don't show again" preference is saved only after successful clock-in. A user click is still required before the browser requests the camera.
- Existing geofence, leave, shop-hours, duplicate-check-in, manual-HR-entry, and payroll formula behavior must remain intact; grace applies to new self-service and manual attendance check-ins.
- Preserve existing dirty user changes. Do not commit, switch branches, stash, or change dependencies.
- PHPUnit uses SQLite `:memory:` in this repository; never run a migration against the local application database for this feature.

---

### Baseline checkpoint: Confirm the current dirty workspace is healthy

**Files:** None; read-only verification.

- [x] Run `php artisan test tests/Feature/HR/AttendanceLatenessPrecisionTest.php` and `php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php` before edits; both passed without changing unrelated user work.
- [x] Run the focused Time In, HR attendance, and retail warranty Vitest files before edits; all 17 tests passed using the local Vitest binary after pnpm's pinned-version fetch failed.
- [x] If a baseline command fails, inspect and report the existing failure before building on it. The standard `pnpm.cmd` launcher stopped at pnpm 11.14 registry-signature verification, so the local Vitest binary was used without changing pnpm configuration.

### Task 1: Persist the grace policy and classify attendance consistently

**Files:**
- Create: `database/migrations/2026_10_22_000001_add_attendance_camera_and_grace_fields.php`
- Modify: `app/Models/ShopOwner.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/HR/AttendanceRecord.php`
- Modify: `app/Http/Controllers/Erp/HR/AttendanceController.php`
- Test: `tests/Feature/HR/AttendanceLatenessPrecisionTest.php`

**Interfaces:**
- `ShopOwner::late_grace_period_minutes` is an integer defaulting to `0`.
- `AttendanceRecord::late_grace_period_minutes` is nullable for legacy rows; new check-ins snapshot the current shop value.
- `AttendanceRecord::check_in_photo_path` and `check_in_photo_captured_at` are nullable; the path is hidden from serialization.
- `User::attendance_camera_prompt_dismissed` is a boolean defaulting to `false`.

- [x] **Step 1: Add regression cases for the grace boundary and snapshot.** Extend `AttendanceLatenessPrecisionTest` with persisted attendance records for zero grace, exactly 15 minutes late under a 15-minute grace, and 16 minutes late under that same grace. Assert the expected `status`, `is_late`, `minutes_late`, and saved snapshot. Add a case where a later shop setting change leaves an existing record's snapshot and classification unchanged.
- [x] **Step 2: Run the focused test before implementation.** Run `php artisan test tests/Feature/HR/AttendanceLatenessPrecisionTest.php`. The new grace cases must fail because the grace field and threshold behavior do not exist yet; existing precision cases should still pass.
- [x] **Step 3: Add the reversible migration.** Add `shop_owners.late_grace_period_minutes` as an unsigned tiny integer default `0`; add `users.attendance_camera_prompt_dismissed` as boolean default `false`; add nullable `attendance_records.check_in_photo_path`, `check_in_photo_captured_at`, and `late_grace_period_minutes`. In `down()`, drop only these added columns.
- [x] **Step 4: Add model fillable/cast/hidden entries.** Add the grace setting and integer cast to `ShopOwner`; the preference and boolean cast to `User`; and the evidence/snapshot fields to `AttendanceRecord`, casting the timestamp and nullable integer and hiding the private path.
- [x] **Step 5: Snapshot and apply grace in `AttendanceRecord`.** Before calculating lateness, when `check_in_time` is new or changed and the snapshot is null, read the same-shop `ShopOwner` grace value (fall back to `0`). Compare minute-rounded check-in and expected times; at or below the saved threshold set `present/false/0`; above it set `late/true` and retain the full actual minutes late. Legacy null snapshots use threshold `0` and are not filled during checkout-only saves.
- [x] **Step 6: Fix the status endpoint fallback.** In `AttendanceController::checkStatus`, compare derived lateness with the attendance record's saved snapshot (null means zero) so a stale persisted flag cannot display a within-grace check-in as late. Include `attendance_camera_prompt_dismissed` in the status JSON response.
- [x] **Step 7: Re-run the focused test.** Run `php artisan test tests/Feature/HR/AttendanceLatenessPrecisionTest.php`; all boundary, snapshot, and old minute-precision assertions must pass.

### Task 2: Expose and save the grace setting in Business Settings

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
- Modify: `resources/js/Pages/ShopOwner/Settings/__tests__/shopSetting.attendance-grace.test.tsx`
- Test: `tests/Feature/ShopOwner/AttendanceGraceSettingsTest.php`

**Interfaces:**
- `GET /shop-owner/settings` exposes `late_grace_period_minutes`.
- `PUT /shop-owner/settings` validates and saves `late_grace_period_minutes` from `0` through `120`.

- [x] **Step 1: Add the controller feature test.** Authenticate a shop owner, assert the settings page returns the stored grace value (default `0`), save `15` through `PUT /shop-owner/settings`, then assert the database value. Assert `-1` and `121` are rejected and leave the saved value unchanged.
- [x] **Step 2: Run the focused feature test.** Run `php artisan test tests/Feature/ShopOwner/AttendanceGraceSettingsTest.php` and confirm it fails before the controller/model support is added.
- [x] **Step 3: Add server payload, validation, and persistence.** In `ShopSettingsController::index`, include `(int) ($shopOwner->late_grace_period_minutes ?? 0)`. In `update`, validate `sometimes|integer|min:0|max:120` and add the validated value to the existing `$shopOwnerUpdates` array only when provided.
- [x] **Step 4: Add the settings input and save handler.** In `shopSetting.tsx`, initialize input state from `shop_settings.late_grace_period_minutes ?? 0`. Add an integer input with `min={0}`, `max={120}`, and `step={1}` plus a note that `0` disables grace. Reject non-integer or out-of-range values before sending. Submit `router.put('/shop-owner/settings', { late_grace_period_minutes: value }, { preserveScroll: true, onSuccess: () => set the saved value and show success feedback, onError: (errors) => show errors.late_grace_period_minutes or a generic message })`. Place the card immediately after the Refund Deadline card and outside that card's retail/repair visibility condition so every shop can configure attendance.
- [x] **Step 5: Add the UI regression test.** Follow `shopSetting.retail-warranty.test.tsx` conventions to assert the new setting renders with default `0`, accepts a valid saved value, and exposes range guidance; keep the test scoped to the grace setting.
- [x] **Step 6: Run the focused settings checks.** Run `php artisan test tests/Feature/ShopOwner/AttendanceGraceSettingsTest.php` and `node node_modules/vitest/vitest.mjs run resources/js/Pages/ShopOwner/Settings/__tests__/shopSetting.attendance-grace.test.tsx`.

### Task 3: Require, persist, and authorize clock-in photos

**Files:**
- Modify: `app/Http/Controllers/Erp/HR/AttendanceController.php`
- Modify: `routes/hr-api.php`
- Modify: `app/Models/HR/AttendanceRecord.php`
- Modify: `tests/Feature/HR/EmployeeClockInAccessTest.php` (preserve all existing user edits)
- Test: `tests/Feature/HR/AttendanceClockInEvidenceTest.php`

**Interfaces:**
- `POST /api/staff/attendance/check-in` requires multipart field `photo`; optional boolean field `dismiss_camera_prompt` opts into the account-level preference.
- `GET /api/hr/attendance/{id}/check-in-photo` streams a private photo only to same-shop viewers with `access-attendance-records`.

- [x] **Step 1: Inventory current self-check-in callers.** Run `rg -n "postJson\('/api/staff/attendance/check-in'|post\('/api/staff/attendance/check-in'" tests`. Keep unauthorized requests without a photo when the test is specifically verifying an earlier middleware denial; attach a fake image to requests expected to exercise controller clock-in behavior.
- [x] **Step 2: Add backend photo and access tests.** In `AttendanceClockInEvidenceTest`, use multipart `post()` requests, `Storage::fake('local')`, and a fixed PNG fixture (PHP GD is unavailable here) to assert a successful self-service check-in stores the photo privately with a timestamp and never serializes the path. Assert missing, invalid, and over-5-MB photos create no attendance record and do not save the preference. Assert a successful request with `dismiss_camera_prompt=true` persists the user preference. Assert same-shop HR can retrieve the image, an authenticated user without attendance access gets `403`, and another shop gets `404` for a known record ID.
- [x] **Step 3: Run the new test before implementation.** Run `php artisan test tests/Feature/HR/AttendanceClockInEvidenceTest.php`; new assertions must fail before the endpoint and route are added.
- [x] **Step 4: Validate the upload before any employee/attendance mutation.** At the start of `selfCheckIn`, validate `photo` as `required|image|mimes:jpeg,jpg,png,webp|max:5120` and `dismiss_camera_prompt` as `sometimes|boolean`. This prevents invalid submissions from reaching the existing employee auto-create path.
- [x] **Step 5: Save the photo and attendance with compensation on failure.** Keep existing leave, duplicate, geofence, and shop-hour checks. After they pass, store the uploaded image on `Storage::disk('local')` under a generated `attendance/{shopOwnerId}/{employeeId}/{date}` path. Fail the request if storage returns no path. Within a database transaction, save the record path, `now()` capture timestamp, current grace snapshot, and the user preference only when `dismiss_camera_prompt` is true and clock-in succeeds. If the database write fails, delete the stored file before returning the error.
- [x] **Step 6: Add the shop-scoped private image endpoint.** Add the GET route within the existing HR attendance route group. The controller must check `access-attendance-records`, fetch with `AttendanceRecord::forShopOwner($user->shop_owner_id)`, return `404` when the row/path/file is missing, and stream the local private file inline with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff` headers. Never include the storage path in JSON or image headers.
- [x] **Step 7: Run the backend checks.** Run the evidence test, `php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php`, and `php artisan test tests/Feature/HR/AttendanceLatenessPrecisionTest.php`. Preserve unrelated modifications already present in `EmployeeClockInAccessTest.php`.

### Task 4: Add the required browser-camera flow to Time In

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/TimeIn.tsx`
- Modify: `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`

**Interfaces:**
- `Clock In` opens the required-photo prompt unless the account preference is already saved; when saved, the same click starts the camera immediately.
- The check-in request uses `FormData` with `photo`, optional `dismiss_camera_prompt`, and any existing geofence coordinates; it does not set a JSON `Content-Type` header.

- [x] **Step 1: Add capture-flow frontend tests.** Mock `navigator.mediaDevices.getUserMedia`, video playback, and `canvas.toBlob`. Assert the initial Clock In click shows the required-photo prompt, the Open Camera action requests a video-only stream, capture produces a preview, cancel never submits, confirm submits multipart photo data, and a saved account preference starts the camera from the Clock In click without showing the prompt.
- [x] **Step 2: Run the focused frontend test before implementation.** The capture-flow tests were added after the initial UI implementation, so a preimplementation red run was not recorded. The final focused Vitest run passed using the locally installed binary because pnpm could not verify its pinned version.
- [x] **Step 3: Add focused camera state and cleanup.** In `TimeIn.tsx`, add refs/state for the video element, media stream, captured `File`, preview URL, prompt checkbox, and camera error. Call `getUserMedia({ video: { facingMode: 'user' }, audio: false })` only in a user click handler. Stop every media track on capture, cancel, retake, permission failure, and unmount; revoke each preview object URL when replaced or cleared.
- [x] **Step 4: Connect capture to existing check-in validation.** Keep shop-hours and geofence logic. Require a captured file before starting `handleClockIn`; submit with `FormData`, CSRF header, and credentials. Leave attendance UI unchanged until the server returns success. Show camera/validation/network errors in the modal and keep the captured file available for retry after a request failure.
- [x] **Step 5: Persist the account preference only through successful requests.** Read the preference from `/api/staff/attendance/status`. When checked, send `dismiss_camera_prompt=true`; when the request succeeds, update local state so the next Clock In click starts camera directly. Do not clear a previously saved preference when the checkbox is unchecked.
- [x] **Step 6: Re-run the Time In frontend test.** Run `node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`; capture, cancel, saved-preference, and existing page behavior passed.

### Task 5: Show the private photo inside the HR Attendance Receipt

**Files:**
- Modify: `app/Http/Controllers/Erp/HR/AttendanceController.php`
- Modify: `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`
- Modify: `resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx`
- Test: `tests/Feature/HR/AttendanceClockInEvidenceTest.php`

- [x] **Step 1: Expose availability without exposing the path.** Add a `has_check_in_photo` boolean accessor to `AttendanceRecord` and append it only to the HR attendance index paginator's data. Keep `check_in_photo_path` hidden.
- [x] **Step 2: Add the HR modal regression test.** Extend `AttendanceRecords.test.tsx` with a non-owner HR fixture and a record with photo availability. Assert the existing eye action still opens the Attendance Receipt and that the image URL points only to `/api/hr/attendance/{id}/check-in-photo`; add a legacy record assertion for "No check-in photo". Assert owner mode does not request the HR-only endpoint.
- [x] **Step 3: Render the photo in the existing receipt.** Carry `has_check_in_photo` through `transformAttendanceFromApi`. In the receipt modal, render an accessible `<img>` only for non-owner HR mode when the flag is true; use the protected route, and show "No check-in photo" for records without a photo. If image loading fails, replace it with the same unavailable state.
- [x] **Step 4: Run HR photo and frontend checks.** Run `php artisan test tests/Feature/HR/AttendanceClockInEvidenceTest.php` and `node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx`.

### Task 6: Payroll regression review and final verification

**Files:**
- Review: `app/Http/Controllers/Erp/HR/PayrollBatchController.php`
- Test: `tests/Feature/HR/PayrollControllerTest.php`
- Review: every changed file and the working tree

- [x] **Step 1: Add a payroll-input regression assertion.** Verify that a 15-minute arrival under a saved 15-minute grace contributes zero late days and zero late hours to the existing batch attendance payload, while a 16-minute arrival contributes one late day and the full 16 late minutes. Do not alter payroll formulas.
- [x] **Step 2: Run relevant backend tests.** Run `php artisan test tests/Feature/HR/AttendanceLatenessPrecisionTest.php`, `php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php`, `php artisan test tests/Feature/HR/AttendanceClockInEvidenceTest.php`, `php artisan test tests/Feature/HR/AttendanceLunchTest.php`, `php artisan test tests/Feature/HR/AutoClockOutEmployeesTest.php`, `php artisan test tests/Feature/HR/PayrollControllerTest.php`, and `php artisan test tests/Feature/ShopOwner/AttendanceGraceSettingsTest.php`.
- [x] **Step 3: Run relevant frontend tests and production build.** The three focused Vitest files passed using `node node_modules/vitest/vitest.mjs`. `node node_modules/vite/bin/vite.js build --outDir C:/Users/Acer/AppData/Local/Temp/solespace-attendance-camera-build-20261022` passed. The standard pnpm launcher could not verify its pinned package-manager version due registry-signature verification, so the local binaries were used.
- [x] **Step 4: Review final scope and data safety.** Run `git status --short`, `git diff --stat`, `git diff --check`, and inspect each task-modified file. Confirm task changes stay within the planned feature files and approved design/plan docs, pre-existing unrelated changes remain intact, private paths are hidden, no public photo URLs/debug output exist, and no payroll formulas or historical rows are rewritten. Do not commit.
