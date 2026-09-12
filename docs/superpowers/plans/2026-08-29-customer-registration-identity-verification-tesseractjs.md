# Client-Side Tesseract.js Identity Screening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the customer-registration PaddleOCR/FastAPI dependency with browser-side Tesseract.js while keeping Laravel authoritative for validation, screening states, private storage, authorization, and human review.

**Architecture:** The customer browser will use a single Tesseract.js worker with the English (`eng`) model to extract untrusted OCR text and confidence from the selected image. Laravel will validate the original upload, store it privately, sanitize the submitted metadata, and evaluate the selected document type against centralized screening rules. Client metadata can produce only `automated_check_passed` or `manual_review_required`; only the existing authorized reviewer can set `review_status` to `approved` or `rejected`.

**Tech Stack:** Laravel 12/PHP 8.2, Inertia 2, React 18/TypeScript, Vite, Tesseract.js, PHPUnit, Vitest.

---

### Scope extension: document-specific screening rules

The implemented classifier extension keeps the same architecture and adds centralized rules for the four supported customer ID types. National ID uses official title/PhilSys signals, a 16-digit PCN pattern, and a browser-reported QR-presence boolean; missing QR is manual review. Driver's License requires the license title, LTO, Philippine issuer, core identity fields, a labelled issue date, and a current expiry; electronic-license/screenshot signals are manual review. Passport requires a Philippine TD3 MRZ with ICAO check digits, a current expiration date, and consistency with visible labelled fields. UMID requires UMID/unified-identification, SSS or GSIS, and a CRN pattern. Novelty/non-document signals are rejected, while photo, cropping, front/back completeness, holograms, chips, signatures, and genuine government issuance remain reviewer-only checks.

The registration contract now retains the original front image and, for physical card IDs, a separate back image. The server requires both card sides before account creation; a passport requires only its biodata page. Image quality, cropping, and visual security features remain human-review concerns.

### Follow-up: fail-fast upload rejection and physical card sides

The registration contract is extended for the four supported types: National ID,
Driver's License, and UMID require separate front and back image uploads;
Passport requires only its biodata page. The browser performs a conservative
preflight after front-image OCR and immediately clears/rejects explicit novelty
signals or a strong selected-type mismatch, then asks the customer to upload a
clear real ID. This is a UX optimization, not an authenticity decision.

Laravel repeats the novelty/mismatch ordering before low-confidence fallback,
requires the back image for physical card types, stores both images on the
private disk, and exposes the second image only through the existing privileged,
audited document viewer. Low-confidence or ambiguous evidence without a clear
negative signal still goes to manual review.

### Task 1: Replace the server OCR contract with a browser OCR helper

**Files:**
- Modify: `package.json`
- Modify: `pnpm-lock.yaml`
- Create: `resources/js/Pages/UserSide/Auth/registrationOcr.ts`
- Test: `resources/js/Pages/UserSide/Auth/registrationOcr.test.ts`

- [x] **Step 1: Write failing helper tests**

Cover that the helper:

- creates an English Tesseract worker and returns trimmed text plus a normalized 0–1 confidence value from Tesseract’s 0–100 result;
- reports progress stages for loading/recognizing;
- terminates the worker after success and after failure;
- rejects an OCR result with no usable text as an uncertain result rather than throwing an unhandled error.

- [x] **Step 2: Run the focused frontend test and verify the expected failure**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Auth/registrationOcr.test.ts`

Expected: FAIL because the helper and dependency are not yet present.

- [x] **Step 3: Add the smallest Tesseract.js helper**

Install `tesseract.js` using pnpm. Implement a narrow `readRegistrationId(file, onProgress?)` function that creates an English worker, returns `{ text, confidence }`, never logs OCR values, and always terminates the worker. Keep the helper free of Laravel/business status decisions.

- [x] **Step 4: Run the focused frontend test and verify it passes**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Auth/registrationOcr.test.ts`

Expected: PASS.

### Task 2: Make the Laravel classifier consume untrusted browser metadata

**Files:**
- Modify: `config/identity_verification.php`
- Modify: `app/Services/IdentityDocumentClassifier.php`
- Test: `tests/Feature/UserSide/IdentityVerificationScreeningTest.php`

- [x] **Step 1: Add failing classifier tests**

Cover:

- a selected supported type with multiple signals and sufficient confidence reaches `automated_check_passed`;
- low confidence, missing text, insufficient signals, and ambiguous type evidence reach `manual_review_required`, while clear selected-type mismatches are rejected;
- unsupported selected types, non-string text, oversized text, invalid confidence, and client-supplied status/classification fields never produce approval;
- document definitions remain centralized and the classifier does not require the removed structured FastAPI response.

- [x] **Step 2: Run the focused Laravel test and verify the expected failure**

Run: `php artisan test tests/Feature/UserSide/IdentityVerificationScreeningTest.php`

Expected: FAIL because the classifier still expects a PaddleOCR response and calls the HTTP service.

- [x] **Step 3: Implement server-side classification from sanitized text**

Change the classifier contract to accept the selected document type, browser OCR text, browser confidence, and the registering user’s name. Whitelist the document type, normalize text with Laravel string helpers, calculate signal/field evidence on the server, derive `classification_confidence`, and return only screening/review fields. Never accept client-supplied status, document detection, classification confidence, or field flags.

Use the existing definitions for `national_id`, `drivers_license`, `passport`, and `umid`, adding only the minimum field patterns/signals needed to classify text. Keep weak or ambiguous evidence in `manual_review_required`; do not automatically reject poor OCR.

- [x] **Step 4: Run the focused Laravel test and verify it passes**

Run: `php artisan test tests/Feature/UserSide/IdentityVerificationScreeningTest.php`

Expected: PASS for the classifier cases.

### Task 3: Remove HTTP OCR from the identity service and validate browser metadata

**Files:**
- Modify: `app/Services/IdentityVerificationService.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `tests/Feature/UserSide/IdentityVerificationScreeningTest.php`
- Modify: `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`

- [x] **Step 1: Add failing registration trust-boundary tests**

Cover:

- registration accepts valid browser metadata and persists the original file privately;
- missing, malformed, low-confidence, tampered, or ambiguous metadata is recoverable as manual review; clear OCR evidence of a selected-type mismatch is rejected before account creation;
- client values cannot set `approved`, overwrite review status, or persist raw OCR text;
- the service makes no HTTP request to an OCR endpoint;
- shop-owner/Cavite registration is not changed.

- [x] **Step 2: Run the focused tests and verify the expected failure**

Run: `php artisan test tests/Feature/UserSide/IdentityVerificationScreeningTest.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php`

Expected: FAIL because registration still calls the removed HTTP OCR client and does not accept browser metadata.

- [x] **Step 3: Implement the service/controller boundary**

Update `UserController::register()` validation for a required supported `document_type`, bounded `ocr_text`, and a numeric Tesseract confidence value. Pass only validated data to `IdentityVerificationService`.

Update `IdentityVerificationService` to store the original file on the existing private disk, create `processing`, call the Laravel classifier synchronously, and persist only safe screening/review metadata. On missing/invalid metadata, use `manual_review_required` with a safe failure code. Remove HTTP client imports, timeout handling, URL/token lookup, and all OCR response parsing.

- [x] **Step 4: Run the focused tests and verify they pass**

Run: `php artisan test tests/Feature/UserSide/IdentityVerificationScreeningTest.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php`

Expected: PASS.

### Task 4: Integrate Tesseract.js into customer registration UX

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/Register.tsx`
- Modify: `resources/js/Pages/UserSide/Auth/registrationDocumentPayload.ts` only if shared payload typing is used by the active form
- Test: `resources/js/Pages/UserSide/Auth/registrationOcr.test.ts` and/or a focused Register component test if the existing test harness supports it

- [x] **Step 1: Add failing UI behavior tests**

Cover that selecting a supported file shows an OCR loading state, successful OCR enables submission with `document_type`, `ocr_text`, and `ocr_confidence`, OCR failure leaves submission available for manual review, and the client never sends `approved` or review status fields.

- [x] **Step 2: Run the focused frontend test and verify the expected failure**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Auth/registrationOcr.test.ts`

Expected: FAIL because the form does not run Tesseract or include its result.

- [x] **Step 3: Integrate the helper with minimal form state**

Add a required document-type select for the four supported types, OCR state/progress/error state, and an async file-drop handler. Run OCR in the browser after local extension/MIME/size validation. Preserve the original `File` object for upload. If OCR fails or returns no text, show a recoverable message and submit empty/uncertain metadata for manual review. Disable the final action only while OCR is actively processing.

Send the original file plus the selected type, OCR text, and confidence in the existing Inertia `FormData`. Do not display raw OCR text or confidence to customers. Use accessible labels, `aria-live` status feedback, clear error recovery, and existing styling/modal patterns.

- [x] **Step 4: Run the focused frontend test and build**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Auth/registrationOcr.test.ts`

Then run: `pnpm run build`

Expected: PASS and a successful Vite build.

### Task 5: Remove obsolete PaddleOCR/FastAPI configuration and documentation

**Files:**
- Delete: `services/id-ocr/app.py`
- Delete: `services/id-ocr/requirements.txt`
- Delete: `services/id-ocr/README.md`
- Modify: `.env.example`
- Modify: `config/identity_verification.php`
- Modify: `docs/superpowers/specs/2026-08-29-customer-registration-identity-verification-design.md`
- Modify: `docs/superpowers/plans/2026-08-29-customer-registration-identity-verification.md` or mark it superseded by this plan

- [x] **Step 1: Add a repository-level regression check for obsolete integration references**

Use a focused search assertion/check that no active application/configuration file references `PaddleOCR`, `FastAPI`, `Uvicorn`, `ID_OCR_URL`, `ID_OCR_TOKEN`, or the removed timeout keys. Keep unrelated historical documentation references out of active configuration.

- [x] **Step 2: Remove the old service and configuration**

Delete only the OCR service files created for this feature, remove OCR environment/config entries and HTTP-only tests, and update the design/plan documentation to describe the client-side architecture and its limitations.

- [x] **Step 3: Run the reference check**

Run: `rg -n -i "paddleocr|fastapi|uvicorn|id_ocr_url|id_ocr_token|id_ocr_connect_timeout|id_ocr_timeout" app config routes resources tests services .env.example package.json`

Expected: no active code/configuration matches.

### Task 6: Complete verification and sequential review

**Files:**
- Review all changed files.

- [x] **Step 1: Run focused Laravel tests**

Run: `php artisan test tests/Feature/UserSide/IdentityVerificationScreeningTest.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php tests/Feature/SuperAdmin/IdentityVerificationReviewTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`

- [x] **Step 2: Run frontend tests and build**

Run: `pnpm run test:frontend`

Then run: `pnpm run build`

- [x] **Step 3: Run hygiene checks**

Run: `git diff --check`

Run a changed-area dead-code/reference scan for removed imports, obsolete status fields, and accidental shop-owner changes.

- [x] **Step 4: Perform the required review stack**

Record results for simplify/ponytail, sequential standards/spec/correctness review, changed TypeScript/React review, Karpathy minimum-scope review, bundle/code-splitting review, evidence/gauge review, and security review for untrusted OCR, file uploads, private storage, CSRF, and authorization.

- [x] **Step 5: Report evidence and limitations**

Report exact commands/results, changed files, migration impact, the browser-to-Laravel flow, and the limitation that client OCR is advisory document screening—not proof of authentic government issuance.
