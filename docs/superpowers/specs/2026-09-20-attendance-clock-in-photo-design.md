# Attendance Clock-In Photo Evidence Design

**Date:** 2026-09-20
**Status:** Approved

## Goal

Require a live camera photo before an employee's self-service clock-in can be created, then let authorized HR/attendance viewers inspect that private photo from the attendance page.

## Scope and non-goals

- Clock-in photo is mandatory for the employee self-service endpoint used by the Time In page.
- Capture uses the browser camera (`getUserMedia`) with a live preview; gallery/file selection is not offered.
- Cancelled capture, denied permission, capture failure, invalid photo, or missing photo must leave attendance unchanged.
- The photo is evidence of the clock-in event only. It is not presented as face or identity verification.
- Existing manual HR attendance entry remains available for authorized staff and does not require a self-service camera photo.

## Design

### Employee capture flow

1. Employee clicks **Clock In**.
2. A modal requests the front-facing camera and shows the live stream.
3. The employee captures a frame, then sees a preview with **Retake**, **Cancel**, and **Confirm & Clock In**.
4. Confirm submits the captured image together with the existing geofence coordinates.
5. The modal stops all media tracks on cancel, retake, successful submission, permission failure, and unmount.
6. Permission and capture errors explain that a photo is required and how to re-enable camera permission.

The client sends a `multipart/form-data` request. It never marks the employee clocked in locally before the server confirms success.

### Server and storage

- Add nullable `check_in_photo_path` and `check_in_photo_captured_at` columns to `attendance_records`.
- Validate the upload as a required image with an allowlisted JPEG/PNG/WebP MIME/extension and a bounded size.
- Store the file on the private `local` disk under a tenant- and employee-scoped attendance directory.
- Store the photo and attendance update/create in one guarded workflow. If persistence fails after the file is stored, delete the uncommitted file; no incomplete attendance record is returned.
- Return only photo availability and a protected application route, never the storage path or public URL.
- Add a protected photo endpoint that verifies the viewer belongs to the same shop and has attendance-record access (or is viewing their own record), then streams the local file with private/no-cache and `nosniff` headers.

### HR attendance page

- Include a photo-available flag and protected photo URL in the tenant-scoped attendance list response.
- Show an eye/view button only when a photo exists.
- Clicking the button opens a modal with the photo, employee/date context, close control, and a useful unavailable state.
- The frontend uses the protected endpoint and never constructs a storage URL.

## Testing

Backend feature coverage will verify:

- Missing/invalid photo returns validation failure and creates no clock-in.
- A valid photo creates the record and stores the captured timestamp/path.
- Authorized same-shop viewers can stream the photo.
- Cross-shop and unauthorized viewers cannot access it.
- Existing geofence and clock-in rejection paths remain unchanged.

Frontend coverage will verify:

- Clock In opens the capture flow and does not submit before confirmation.
- Cancel, permission failure, and capture failure do not call the check-in endpoint.
- Confirm sends the captured image and displays the attendance result.
- HR's eye button opens/closes the photo modal and is absent when no photo is available.

## Acceptance criteria

- No valid photo means no attendance record is created or updated.
- A successful clock-in always has a private check-in photo path.
- HR/authorized attendance viewers can inspect the correct employee photo through the eye button.
- A user from another shop cannot retrieve the photo, even if they know an attendance ID.
- No raw storage path or public image URL is exposed to the browser.
- Camera streams are stopped whenever the capture modal closes or finishes.
