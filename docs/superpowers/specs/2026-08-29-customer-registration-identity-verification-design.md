# Customer Registration and Identity Screening Design

Date: 2026-08-29

## Scope

This change applies to customer registration at `POST /user/register`. Cavite registration is the shop-owner flow; `ShopOwnerAuthController`, `POST /shop-owner/register`, and shop-owner business-document processing remain unchanged.

The change has two independent safeguards:

1. an email-verification access boundary for customer accounts; and
2. browser-side OCR for preliminary screening of the customer registration ID.

OCR is a document-screening aid only. It does not prove that an ID is genuine, authentic, officially verified, or issued by a government agency.

## 1. Customer email-verification boundary

Customers are identified explicitly by `User::isCustomerAccount()`: no `shop_owner_id` and a blank or `CUSTOMER` legacy role. The centralized gate therefore does not accidentally restrict employees or shop owners that share portions of the user authentication stack.

`EnsureCustomerEmailIsVerified` runs after authentication on protected customer routes. It leaves unauthenticated requests, employees, shop owners, and verified customers alone. An unverified customer may use only the verification notice, resend, signed verification handling, logout, and other strictly necessary verification routes. Customer dashboard, checkout, orders, payments, repairs, sensitive profile actions, identity actions, and other customer workflows remain unavailable until verification.

Registration creates the customer with `email_verified_at = null`, dispatches Laravel's existing `Registered` event, signs the customer into the user guard, regenerates the session, and redirects to the verification notice. The notice displays the account email, resend feedback/cooldown, logout, and safe next-step messaging.

The verification link remains Laravel's temporary signed route. It requires an authenticated matching customer, validates the email hash against that same account, is throttled, and is idempotent for an already verified account. Expired, invalid, tampered, duplicate, or cross-account links fail safely. Successful verification marks the account verified, regenerates the session, preserves the intended destination where safe, and flashes the existing success notification.

## 2. Identity-verification persistence

`identity_verifications` keeps screening and human review separate:

```text
screening_status: pending | processing | automated_check_passed | manual_review_required | rejected
review_status:    not_required | pending | approved | rejected
```

It stores the customer, selected document type, private front/back file references, normalized confidence metadata, safe failure reason, reviewer, review timestamp, and timestamps. It does not store full OCR text. Existing `users.valid_id_path` and `valid_id_disk` remain synchronized for compatibility with the protected privileged viewer.

An automated pass sets `screening_status=automated_check_passed` and `review_status=not_required`. An uncertain or incomplete result sets `screening_status=manual_review_required` and `review_status=pending`. Obvious non-document content may be `rejected` with `review_status=not_required`. An authorized reviewer may transition either screened state (`automated_check_passed` or `manual_review_required`) to `review_status=approved` or `review_status=rejected`; the screening result is preserved.

## 3. Browser OCR and Laravel trust boundary

The registration page uses Tesseract.js in the customer browser:

```text
Customer browser
  -> Tesseract.js English worker
  -> OCR text + confidence metadata
  -> Laravel upload validation and private storage
  -> Laravel classifier/configuration rules
  -> automated_check_passed or manual_review_required
  -> authorized reviewer -> approved or rejected review status
```

Tesseract.js runs in the browser after local file type and size checks. The original front `File` and, for physical card IDs, the original back `File` are retained and submitted with the selected `document_type`, OCR text, and OCR confidence. The UI shows reading/checking/ready/failure states, does not display raw OCR or confidence, and allows submission after OCR failure so the case can go to manual review.

Every OCR value received by Laravel is untrusted. Laravel validates the selected type against the supported configuration, bounds the text and confidence, ignores client-supplied statuses, field flags, document-detection flags, and classification confidence, and derives screening evidence itself. Browser data can never set `approved` or `rejected` final review status.

The classifier uses centralized definitions for `national_id`, `drivers_license`, `passport`, and `umid`. Each definition supplies identifying signals, required signal groups, upload guidance, required fields, safe ID-number patterns, and applicable structures. Server-side checks look for multiple signals, the registered holder name, plausible dates, and document-specific evidence; a single keyword is insufficient.

The document-specific screen is deliberately conservative:

- `national_id` requires PhilSys/official title evidence, a 16-digit PCN pattern, the registered name, birth date, address text, and a QR code detected by the browser's native barcode capability. The QR value is never retained or treated as an official PhilSys verification; missing or unreadable QR evidence goes to manual review. The underlying PSN is never requested or stored.
- `drivers_license` requires a driver's-license title, LTO/ Land Transportation Office evidence, Philippine issuer evidence, holder name, birth date, address, license-number pattern, a labelled issue date, and a current expiration date. Electronic-driver-license or screenshot signals always go to manual review. Novelty/sample signals, including the SpongeBob test case, are rejected as unsupported documents.
- `passport` requires passport and Philippine issuer evidence plus a two-line TD3 MRZ beginning with `P<PHL`. The server checks MRZ structure, ICAO check digits, current expiration, and consistency with any visible passport number and labelled dates in the submitted OCR text. Invalid or incomplete MRZ data goes to manual review.
- `umid` requires UMID/unified-identification evidence, an SSS or GSIS issuer signal, the registered name, birth date, and a CRN/common-reference-number pattern. Holograms, chips, engravings, signatures, and other visual security features remain reviewer checks.

Photo presence, cropping, visual completeness, layout, holograms, chips, signatures, and genuine government issuance cannot be established safely by Tesseract or the native QR detector. Physical card types require separate front and back uploads, but image quality and visual security checks remain part of the final human review. A passport requires only its biodata page.

Low confidence, missing fields, missing QR/MRZ, weak evidence, and ambiguous type evidence go to manual review. Clear selected-type mismatches and configured novelty/non-document signals are rejected before an account is created.

## 4. Upload privacy and authorization

Laravel checks the actual uploaded MIME type, supported extension mapping, readable image content, and the 5 MB limit before screening. Storage uses the private local disk and generated UUID filenames; original names and client paths are not trusted. No `/storage/...` URL is created for the ID.

Privileged viewing continues through the authorization-checked sensitive-document controller and audit trail. Customer identity routes are customer-only. OCR text, names, dates of birth, ID numbers, QR contents, and other extracted values are not logged or returned in ordinary frontend/admin list payloads. The reviewer receives safe workflow metadata and uses the existing protected document viewer for the submitted images.

## 5. Failure handling and limitations

There is no Python process, FastAPI endpoint, persistent OCR server, OCR URL, OCR token, PHP shell call, or deployment service to configure. A browser worker initialization/processing failure or empty result is represented in the UI and submitted as uncertain metadata for `manual_review_required`. Laravel upload/storage failures still fail safely and do not expose the document.

`automated_check_passed` means only that the submitted image and untrusted OCR metadata appear consistent with the selected supported document under the configured screening rules. It is not final approval and is not evidence of genuine government issuance. Final approval or rejection remains a human review decision.

## 6. Verification and acceptance criteria

Tests cover:

- unverified customer registration, notification, verification-only access, matching signed verification, expiry/invalid/duplicate links, resend throttling, and restored access;
- private image validation/storage, supported and uncertain browser OCR metadata, missing/tampered payloads, per-ID signal groups, National ID QR handling, Driver's License issuer/eDL handling, Passport MRZ/checksum and field consistency, UMID CRN/issuer handling, type mismatch, novelty/non-document handling, and no raw OCR persistence;
- no HTTP OCR dependency and no cross-account document access;
- authorized reviewer approval/rejection from both screened states while preserving `screening_status`;
- browser OCR progress, failure fallback, original front/back-file submission, and absence of final approval fields.

The implementation is accepted when customer access remains blocked until email verification, shop-owner/employee flows remain unaffected, ID files remain private, browser OCR remains advisory, and the relevant Laravel/frontend tests and build pass.
