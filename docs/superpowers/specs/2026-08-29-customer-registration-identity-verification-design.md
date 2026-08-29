# Customer Registration and Identity Verification Design

Date: 2026-08-29

## Scope

This change fixes customer access before email verification and adds automated screening for registration identity documents. The implementation stays within the existing Laravel, Inertia, React, and private-document patterns. PaddleOCR is a document-screening component only; it is not an authority for government authenticity.

## 1. Authentication and access boundary

Customers are identified explicitly as `User` accounts with no `shop_owner_id`. Shop owners and employee accounts are not treated as customers by the verification gate, even when they share the `user` authentication stack or implement `MustVerifyEmail`.

An `EnsureCustomerEmailIsVerified` middleware will run after authentication in the web and API flows. It will:

- do nothing for unauthenticated requests, shop owners, and employee accounts;
- do nothing for customers whose `email_verified_at` is set;
- allow only verification notice, resend, signed verification handling, and logout for an unverified customer;
- redirect browser/Inertia requests to the verification notice with a safe message;
- return a `403` JSON response with a stable verification-required code for API requests.

Customer page and action routes that are currently public but represent protected workflows will also require the customer guard. This includes dashboard, checkout, payment, orders, delivery/dispute actions, repairs, sensitive profile actions, notifications, tracking, and identity-verification actions. Public catalog and informational pages remain public.

The existing login behavior may leave an unverified customer authenticated, but every subsequent protected request is gated. Logout remains available.

## 2. Laravel email verification flow

Registration continues to create a customer with `email_verified_at = null`, dispatch Laravel's `Registered` event, and send the existing verification notification. It then signs the customer into a verification-only session and redirects to the verification notice.

The verification route keeps Laravel's signed-link protection and adds authentication, throttling, and explicit principal matching. The current authenticated user must use the same guard/account type and have the same database ID as the signed URL. The endpoint validates the hash against that account's email, calls `markEmailAsVerified()`, and dispatches `Verified` when appropriate. It never logs a user in from a link and never verifies the account identified by a link when a different account is authenticated.

Successful verification regenerates the session and redirects to the intended customer destination or the normal customer landing route with the existing success flash. Already-verified requests are idempotent and return a success-oriented result without changing account ownership. Invalid, expired, tampered, or duplicate links fail closed and do not modify verification state.

The verification notice and result pages will use the existing Inertia/React notification patterns. The notice will show the email address, resend action and cooldown, logout, and a customer-safe identity-screening status. It will not promise normal platform access until email verification succeeds.

## 3. Identity-verification persistence

A focused `identity_verifications` table and model will be added rather than adding more workflow state to `users`. It will contain:

- `user_id` with cascading deletion;
- nullable `document_type`;
- separate `screening_status` and `review_status` fields;
- private `file_path` and `file_disk` references;
- nullable OCR and classification confidence values;
- a safe, non-sensitive `failure_reason` code;
- nullable `reviewed_by` and `reviewed_at` fields;
- timestamps and indexes for user/status lookup.

The automated state machine is:

```text
screening_status: pending | processing | automated_check_passed | manual_review_required | rejected
review_status:    not_required | pending | approved | rejected
```

An automated pass sets `screening_status=automated_check_passed` and `review_status=not_required`. An uncertain result or temporary OCR failure sets `screening_status=manual_review_required` and `review_status=pending`. An obvious invalid or unsupported document sets `screening_status=rejected` and `review_status=not_required`. Human approval or rejection changes only `review_status`, preserving the automated result. Human review is therefore distinguishable from automated rejection.

The existing `users.valid_id_path` and `valid_id_disk` values remain populated for compatibility with the current protected admin document viewer. The identity-verification record is authoritative for the new workflow. Existing documents and legacy records remain readable through the existing authorization path.

## 4. Upload and screening service

Laravel validates registration documents before any OCR request. Accepted formats are JPG, JPEG, PNG, and WEBP. Validation checks the request MIME type, trusted extension/MIME mapping, a maximum size of 5 MB, and actual image readability. Original filenames and client-provided paths are never used for storage.

The document is stored on the existing private local disk under a generated path. The browser receives no public file URL. Document access continues through an authorization-checked controller and existing privileged audit behavior.

`IdentityVerificationService` owns the screening workflow. It creates the record, stores the private file, calls the internal OCR endpoint, validates the response shape, invokes the centralized Laravel classifier, and persists only the status/metadata required by the workflow. Controllers do not spawn Python processes or contain document keyword rules.

The service calls a persistent FastAPI service at `POST /v1/ocr` using multipart form data and an internal application token. It uses bounded connect/read timeouts. Service unavailability, timeouts, malformed responses, and processing exceptions are caught and converted to `manual_review_required` with a safe failure code. They cannot abort registration or corrupt the account transaction.

## 5. OCR service and classification

The new `services/id-ocr` service contains a FastAPI application and dependency definition. PaddleOCR is initialized once when the process starts so requests reuse the loaded model. The endpoint accepts an image, performs image/document analysis, and returns structured internal results such as document detection, candidate type, confidence values, expected-field flags, and temporary OCR text needed only for Laravel classification.

Raw OCR text is not stored, logged, or returned to normal frontend responses. The service is intended for an internal network and rejects requests without the configured token. Request-size and image checks are also applied defensively in the service.

Supported document definitions are centralized in Laravel configuration or a dedicated classifier definition layer for:

- `national_id`;
- `drivers_license`;
- `passport`;
- `umid`.

Each definition contains identifying signals, expected fields, and applicable requirements. The classifier requires multiple corroborating signals and expected fields; a single keyword cannot produce a pass.

The classifier produces only the workflow outcomes described above. `automated_check_passed` means the image appears consistent with a supported document. It does not mean authentic, officially verified, or genuinely government-issued.

## 6. Manual review and administration

Existing privileged document viewing remains the only way to retrieve the private image. New administrative actions will be scoped to a customer and its identity-verification record, protected by the existing account-intervention capability, and audited.

An authorized reviewer may approve or reject a record after reviewing the document. The action updates `review_status`, reviewer identity, and timestamp; it does not overwrite `screening_status`. The admin UI will display safe status/type metadata and review controls without showing raw OCR output or extracted personal data.

## 7. Frontend behavior

The registration form will accept WEBP and describe the upload as automated document screening rather than proof of authenticity. The verification notice will explain that email verification is required before using customer features and will provide resend cooldown and logout controls.

Customer-facing identity results will be mapped to simple messages:

- submitted or automated check passed: the document was received for review;
- manual review required: additional review is needed;
- rejected: upload a clearer image of a supported ID.

Internal confidence scores, OCR text, field values, and failure implementation details are not exposed to customers.

## 8. Tests and acceptance criteria

Feature tests will cover registration, notification dispatch, verification-only access, matching signed verification, invalid/expired/duplicate links, resend throttling, and restored access after verification. Identity tests will cover upload validation, private storage, each screening outcome, OCR failure fallback, separated screening/review statuses, document authorization, manual actions, and absence of sensitive OCR values in frontend payloads.

The implementation is accepted when:

1. an unverified customer cannot reach protected customer workflows;
2. employees and shop owners are unaffected by the customer-only gate;
3. a valid verification link can verify only its matching authenticated account;
4. verified customers regain normal access;
5. uploaded documents remain private and authorized;
6. OCR is persistent, internal, token-protected, and non-authoritative;
7. automated screening and human review are independently represented;
8. temporary OCR failures result in a recoverable state; and
9. the relevant Laravel/frontend checks pass, subject to available local dependencies.

## Out of scope and limitations

This change does not establish government authenticity, query a government registry, or replace human review. It does not add a new external identity provider. Existing public catalog behavior and unrelated authentication flows remain unchanged.
