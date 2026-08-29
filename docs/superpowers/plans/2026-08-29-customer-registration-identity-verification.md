# Customer Registration and Identity Verification Implementation Plan

## Goal

Make email verification a real access boundary for customer accounts, then add private government-ID document screening through a persistent internal FastAPI/PaddleOCR service. Keep automated screening and human review independent, preserve the existing private admin document viewer, and avoid unrelated refactors.

## Constraints and decisions

- Only `User` records with `shop_owner_id === null` are customers for the email gate. Shop owners and employee accounts are explicitly bypassed.
- Laravel remains authoritative for authentication, authorization, validation, classification, workflow state, and manual review.
- The registration ID upload is the existing one-time signup input. Standalone customer identity workflows remain behind the email-verification gate.
- `identity_verifications.screening_status` and `identity_verifications.review_status` are separate fields.
- Existing `users.valid_id_path` and `valid_id_disk` remain populated for admin-viewer compatibility.
- OCR failure is recoverable and maps to `manual_review_required`; no raw OCR values are stored, logged, or returned to normal frontend payloads.
- Use generated private storage paths and the existing protected sensitive-document serving/audit patterns.

## Phase 1 — Email access control

### 1. Add regression tests first

Update or add feature coverage in `tests/Feature/UserSide/CustomerRegistrationAddressTest.php` and a focused email-verification test file if the existing cases become clearer when separated.

Cover:

- registration leaves `email_verified_at` null and dispatches the verification notification;
- unverified customers cannot reach dashboard, checkout, payment, order, repair, profile-sensitive, or identity routes/actions;
- verification notice, resend, and logout remain available;
- shop owners and employee accounts are not blocked by the customer-only middleware;
- a signed link requires an authenticated matching account and cannot verify another user;
- valid verification marks the current account verified and restores protected access;
- invalid, expired, already-verified, and duplicate links fail safely;
- resend is throttled.

Adjust existing tests that currently assert an unverified customer can call protected address APIs so they explicitly verify the account first or assert the new denial.

### 2. Implement the customer-only middleware

Create `app/Http/Middleware/EnsureCustomerEmailIsVerified.php`.

- Resolve the authenticated principal from the request’s supported guards.
- Classify a customer explicitly as an `App\Models\User` with a null `shop_owner_id`.
- Allow verified customers, employees, shop owners, guests, and verification-only route names.
- Redirect Inertia/browser requests to `verification.notice` with a safe flash message.
- Return a stable JSON `403` response for API callers.

Register it in `bootstrap/app.php` after authentication for the relevant web/API flows without changing shared `Authenticate` behavior.

### 3. Secure the verification routes and controller

Update:

- `routes/web.php`
- `app/Http/Controllers/EmailVerificationController.php`
- `resources/js/Pages/UserSide/Auth/VerificationNotice.tsx`
- `resources/js/Pages/UserSide/Auth/VerifyEmail.tsx`

Keep Laravel’s `MustVerifyEmail`, signed URLs, `markEmailAsVerified()`, `Verified` event, and existing notification route. Add authenticated matching-principal checks, throttling, safe session regeneration, intended redirect, and existing flash notifications. Remove auto-login of the account named by an unauthenticated link.

Add logout to the verification notice and revise copy so unverified customers cannot be encouraged to enter the platform.

### 4. Protect currently public customer workflow routes

Apply the customer guard and verification gate to the existing checkout, payment, orders, repairs, and other customer-only workflow routes. Keep public product/catalog/informational routes unchanged. Verify route middleware does not block shop-owner or employee paths sharing the web/API middleware groups.

### 5. Verify Phase 1

Run the focused Laravel feature tests and `git diff --check`. Do not start OCR work until the email regression tests pass or failures are understood and fixed.

## Phase 2 — Identity screening domain and service

### 6. Add persistence and model tests first

Add tests for:

- private file path/disk persistence;
- separate screening/review state transitions;
- supported, uncertain, rejected, malformed, and OCR-unavailable outcomes;
- no raw OCR values in normal Inertia/JSON responses;
- user-scoped document authorization;
- authorized manual approval/rejection preserving the screening result.

Use `Http::fake()`, `Storage::fake('local')`, `Notification::fake()`, and valid in-memory image fixtures so tests do not require PaddleOCR.

### 7. Add identity-verification schema and model

Create:

- a migration for `identity_verifications`;
- `app/Models/IdentityVerification.php`;
- the `identityVerifications()` relationship on `app/Models/User.php`;
- any focused enum/constants needed for screening/review values.

The migration will include `user_id`, nullable `document_type`, `screening_status`, `review_status`, private `file_path`, `file_disk`, nullable confidence values, safe `failure_reason`, reviewer/timestamp fields, indexes, and cascading user deletion. Do not store OCR text or extracted PII.

### 8. Centralize document definitions and classification

Create a configuration/definition layer such as:

- `config/identity_verification.php`;
- `app/Services/IdentityDocumentClassifier.php`.

Define signals and expected fields for `national_id`, `drivers_license`, `passport`, and `umid`. Require multiple corroborating signals and expected fields. Map the internal OCR response to the separate screening/review statuses using conservative thresholds, with uncertain cases sent to manual review.

### 9. Implement the Laravel OCR integration

Create `app/Services/IdentityVerificationService.php`.

- Validate image readability in Laravel in addition to request rules.
- Store the upload on the private local disk under a generated path.
- Create/update `pending` and `processing` records.
- Send multipart data to the configured internal `/v1/ocr` endpoint with an application token and bounded timeouts.
- Validate the response shape and classify it in Laravel.
- Catch timeout, connection, invalid-response, and processing errors and persist `manual_review_required` with a safe code.
- Keep `users.valid_id_path`/`valid_id_disk` synchronized for current admin viewing.
- Never log raw OCR output or include it in frontend payloads.

Update `UserController::register()` to use the service after the account/document transaction is safely established, expand validation to WEBP and actual image validation, and retain generic error responses without leaking exception details.

## Phase 3 — Persistent OCR service

### 10. Add the Python service

Create:

- `services/id-ocr/app.py`;
- `services/id-ocr/requirements.txt`;
- a short `services/id-ocr/README.md` describing local configuration and the internal-only contract.

Implement a FastAPI `POST /v1/ocr` endpoint that:

- checks the internal token with constant-time comparison;
- applies defensive size/image checks;
- loads PaddleOCR once at process startup;
- returns only the structured internal analysis required by Laravel;
- does not log image contents or extracted PII;
- is suitable for a persistent Uvicorn process.

Keep deployment/network exposure documentation minimal and avoid adding unrelated orchestration.

## Phase 4 — UI and manual review

### 11. Update registration and verification UX

Update:

- `resources/js/Pages/UserSide/Auth/Register.tsx`;
- `resources/js/Pages/UserSide/Auth/VerificationNotice.tsx`;
- `resources/js/Pages/UserSide/Auth/VerifyEmail.tsx`;
- any shared Inertia prop/resource mapping required for safe identity status.

Support WEBP, explain automated screening accurately, show only customer-safe status messages, retain resend cooldown, and provide logout.

### 12. Add authorized review actions

Create a narrowly scoped admin identity-verification controller/routes using the existing privileged capability and audit patterns. Scope every action to a customer-owned verification record. Add safe status/type data and approve/reject controls to the existing super-admin user-management view without exposing raw OCR values.

Reuse `PrivateSensitiveDocumentController` for document access; extend it only if the identity record needs a safe, ownership-checked path.

## Phase 5 — Review and final verification

### 13. Run the complete relevant checks

Run, in order:

1. focused Phase 1 Laravel tests;
2. focused Phase 2 Laravel tests;
3. broader `composer test` if dependencies are present;
4. frontend tests/build if the existing dependencies are available;
5. `git diff --check`.

### 14. Perform sequential review gates

- simplify the diff and remove avoidable abstractions;
- review Laravel conventions and specification coverage;
- review changed TypeScript/React for typed boundaries, accessibility, and unnecessary client work;
- review security for authentication, authorization, uploads, sensitive data, API tokens, and private storage;
- scan changed areas for dead code and stale route references;
- record any unavailable checks honestly.

### 15. Final handoff

Report the root cause, changed files, migration, final email/ID flows, security protections, test commands/results, and the remaining limitation that OCR screening does not establish government authenticity.
