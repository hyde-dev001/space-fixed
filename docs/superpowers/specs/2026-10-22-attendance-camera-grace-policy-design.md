# Attendance Camera and Grace Period Design

**Date:** 2026-10-22
**Status:** Approved by user

This extends the approved [clock-in photo evidence design](2026-09-20-attendance-clock-in-photo-design.md) with the required employee camera flow, HR receipt display, and configurable attendance grace period.

## Goal

Require an on-the-spot browser-camera photo for each employee self-service clock-in, let same-shop HR viewers see that photo in the existing Attendance Receipt modal, and let shop owners configure how many minutes after opening count as on time.

## Employee clock-in flow

- Each Clock In click opens a required-photo modal. The employee must click **Open Camera** to start the camera; there is no prompt preference or checkbox.
- Open Camera requests the front-facing camera with `getUserMedia`. The UI provides a live preview, capture, retake, cancel, and confirm actions. It does not offer a gallery or file picker.
- Confirm sends the image and any existing geofence coordinates to the current self-service clock-in endpoint as multipart form data. The page updates attendance only after the server confirms success.
- The server rejects a missing, invalid, or oversized photo before creating or updating attendance. Camera denial, unsupported camera, cancel, or capture failure leaves attendance unchanged.
- Camera tracks stop on cancel, retake, confirm, failure, and component unmount. Existing shop-hours, geofence, leave, duplicate-check-in, and reason flows remain in place.
- Photo evidence records a capture event; it does not claim to prove identity or prevent a modified client/API request from submitting another image.

## Photo storage and HR access

- Store the required JPEG, PNG, or WebP image (maximum 5 MB) on the private local disk in a shop- and employee-scoped attendance directory.
- Add a nullable photo path and server capture timestamp to the attendance record. Hide the path from model serialization and never return it or a public URL to the browser.
- Store the file only after existing eligibility checks pass. If the attendance write fails, remove the newly stored file so an unsuccessful clock-in does not leave orphaned evidence.
- Add a private, no-cache image endpoint authorized for viewers with attendance-record access and scoped to their shop. Requests for another shop's record or a record without an available image return not found.
- The HR View Attendance list keeps its existing eye action and Attendance Receipt modal. Inside that receipt, show the record's photo when available and **No check-in photo** for legacy/manual records without one. Do not create a separate photo modal or expose images to unrelated owner-mode views.

## Grace period

- Add **Late Check-in Grace Period** immediately below the Refund Deadline table/card in Business Settings.
- The shop owner can save an integer from 0 to 120 minutes. Zero is the default and disables the grace period.
- Compare the check-in minute with the record's existing expected check-in time (currently derived from shop hours). If the employee is late by at most the configured grace, save `status=present`, `is_late=false`, and `minutes_late=0`. If the employee is later than the grace, keep the current late classification and full minutes late (for example, 15-minute grace: 15 minutes is on time; 16 minutes is late by 16 minutes).
- Snapshot the configured grace on each newly recorded check-in. Legacy attendance rows remain at zero grace and are not reclassified when the shop setting changes later.
- Use the saved attendance values for payroll inputs and status fallbacks. Do not change payroll formulas, pay rates, overtime, or any other payroll calculation. The only payroll effect is the intended attendance classification/minute value for check-ins within grace.
- The grace policy applies to new attendance check-ins for the shop, including authorized manual attendance entries. The photo requirement remains limited to employee self-service clock-in.

## Data changes

- `shop_owners`: `late_grace_period_minutes`, integer default `0`.
- `attendance_records`: nullable `check_in_photo_path`, nullable `check_in_photo_captured_at`, and nullable `late_grace_period_minutes` (null/legacy values are treated as zero until a new check-in is recorded).
- Migrations are additive and reversible. Existing attendance rows and payroll records are not rewritten.

## Verification and acceptance criteria

- A self-service clock-in without a valid image is rejected and creates or changes no attendance record.
- A successful self-service clock-in stores a private image, capture timestamp, and grace snapshot.
- The HR receipt displays the correct same-shop photo; missing legacy photos show the empty state; another shop or unauthorized viewer cannot retrieve the image.
- Clock In always shows the required-photo modal, has no prompt preference checkbox, and starts the camera only after the employee clicks **Open Camera**.
- Grace tests cover disabled, exactly-at-limit, and one-minute-over-limit cases, plus a changed setting that does not reclassify an older record.
- Existing geofence, attendance, payroll, and clock-in access tests continue to pass. No payroll formulas are changed.
