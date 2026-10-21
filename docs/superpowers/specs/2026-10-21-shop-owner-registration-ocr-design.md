# Shop-owner registration OCR screening design

Date: 2026-10-21

## Status and decisions

The user approved this design in conversation on 2026-10-21. This document records that design for written review before implementation planning. Application implementation has not started.

The agreed decisions are:

- Reuse the customer's browser OCR approach for preliminary admin guidance.
- Assess document type and matching registration details, rather than document type alone.
- Show categorical 100%, 50%, or 0% match scores with reasons; these are not measured accuracy or authenticity probabilities.
- Allow otherwise valid applications at every score, including 0%, and when OCR fails or is unavailable.
- Keep every registration pending until an authorized admin explicitly approves or rejects it under the existing workflow.

## Outcome and scope

Help admins identify suspicious or inconsistent shop-owner registration documents without replacing their review. The feature provides triage evidence; it does not prevent a determined applicant from submitting false browser results.

Screen the four existing required logical slots:

1. `business_registration`, retaining both `dti_registration` and `sec_registration`;
2. `mayors_permit`;
3. `bir_certificate`; and
4. `valid_id`.

Cover new registration, rejected-application resubmission, and the existing admin registration review page. Optional supporting documents remain available for human review and do not affect the score. Existing records without screening show **Not assessed**. There is no automatic historical backfill.

Preserve the current JPG/JPEG/PNG upload contract, 5 MB per-file limit, required document/date metadata, email verification, location policy, signed resubmission access, submission keys, private storage, document history, and admin approval requirements. No new mandatory ID side, applicant field, OCR server, hosted OCR API, government lookup, or automatic approval/rejection is introduced.

## Existing code and reuse boundary

The current checkout contains:

- `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx` and `registrationDocumentPayload.ts` for registration and resubmission uploads;
- `app/Http/Controllers/ShopOwnerAuthController.php` for admission and rejected-application resubmission;
- `app/Services/ShopOwnerDocumentRequirementService.php` for required slots and metadata;
- `app/Services/ShopDocumentLifecycleService.php` for atomic, immutable document-version creation and private file handling;
- `app/Models/ShopDocument.php` for versioned document records;
- `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php` and `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx` for admin review; and
- `app/Services/ShopOwnerRegistrationDecisionService.php` for explicit, locked, audited admin decisions.

Customer OCR was found on `feat/customer-registration-identity-verification` at commit `6540002bdfb0fd63d5f7f643f225343facc8ac77`, rather than in the current checkout. Its reusable sources include `registrationOcr.ts`, `registrationDocumentScreening.ts`, `registrationNameMatch.ts`, and their tests in `resources/js/Pages/UserSide/Auth/`.

Implementation must reconcile that source with the implementation branch before changing it. Reuse the existing OCR reader and applicable matching helpers if already present; otherwise port only the necessary helper source, focused tests, and its existing `tesseract.js` dependency. Do not merge the entire customer feature or copy its identity-verification persistence, admission rejection policy, or front/back requirements into shop-owner registration. Business certificates use the reader's full-image path, with ID-specific cropping disabled.

## Browser flow

1. Validate the selected file using the existing local file checks, retaining the original upload.
2. Load OCR on demand when a required document is selected. Process one document at a time to bound browser resource use; show text progress for loading and reading.
3. Reuse Tesseract.js extraction. Keep extracted text in memory only and run document-specific checks against the current application details and submitted date metadata.
4. Produce bounded structured evidence for the slot. Show a short applicant status such as **Check complete**, **Needs admin review**, or **Unable to assess**. Detailed scores and reasons belong on the admin page.
5. Submit the original files through the existing registration request with optional screening metadata. Do not add an OCR-only persistence endpoint or a background server service.

Replacing or removing a selected file clears its old result immediately. A late callback for an earlier file cannot overwrite the replacement's result. Relevant form edits invalidate affected matches and rerun the lightweight checks against retained OCR text; OCR does not need to reread unchanged selected images. Clear in-memory text when its file is removed or the page is left.

OCR timeout, worker failure, unreadable text, or unavailable OCR assets produce a nonnumeric **Unable to assess** result. Submission remains available while a scan is running; unfinished scans are submitted as unassessed and do not cause later background writes after registration. OCR never bypasses the existing mandatory file, email, location, or date validation.

## Evidence and matching rules

Each applicable check produces `match`, `mismatch`, or `uncertain`. The rules determine which checks apply; an applicant cannot omit a required check by declaring it inapplicable.

| Slot | Type evidence and applicable comparisons |
| --- | --- |
| Business registration | Multiple issuer/title signals for the selected DTI or SEC type, recognizable registration structure, registered business name, and applicable issue/expiry metadata. Compare proprietor details only where that document identifies the proprietor; a company certificate does not have to name the registering representative. |
| Mayor's permit | Local business-permit title/issuer evidence, identifiable permit structure, business name and business address, and readable applicable issue/expiry dates. |
| BIR certificate | BIR certificate-of-registration title/issuer evidence and recognizable certificate structure, relevant registered/trade business details and address, and applicable date metadata. A number pattern supports document-type recognition but is not an official TIN lookup. |
| Owner ID | Reuse the customer's supported ID-family evidence and name matcher for the currently required image. Compare the owner's first and last names and applicable dates. Do not compare the holder's residential address to the shop address. |

A single keyword, uploaded filename, logo claim, or number pattern is insufficient for a complete type match. An unfamiliar but plausible government ID or certificate layout is uncertain, not automatically a wrong document. Retain existing owner ID upload acceptance; do not silently restrict admission to the customer's four supported ID families.

Normalize case, whitespace, and punctuation and reuse existing name handling. Do not introduce a fuzzy-matching dependency. Missing labels, OCR errors, address abbreviations that cannot be resolved safely, differing legal/trade names, and ambiguous date formats produce uncertainty. A name's absence from OCR alone is not a confirmed mismatch. A mismatch requires a confidently extracted conflicting value in the relevant field or clearly incompatible document-type evidence.

Only compare dates where applicable to the document. Do not assume every certificate or ID expires, infer annual BIR expiry, or require a company representative's name on a corporate certificate. A confidently read expired date, future issue date, reversed issue/expiry range, or conflict with submitted date metadata is a failed date check. Missing evidence for an applicable date check is uncertain. Evaluate dates using the application clock at submission; the existing server document-validity indicator must also remain visible during admin review.

Preserve the OCR reader's normalized 0-to-1 recognition confidence. Reuse its screening bands: high is at least 0.85, medium is at least 0.65, and lower values are low. These bands limit interpretation of extracted text; they are not the displayed match score or calibrated fraud probabilities. Do not assign a definitive 0% or 100% from medium/low-confidence reading alone.

## Exact scoring

For a completed per-document assessment, apply these rules in order:

| Condition | Score | Admin explanation |
| --- | --- | --- |
| High-confidence evidence establishes the wrong document type or a conflicting required detail/date | 0% | Flag the specific conflict; the application still needs an admin decision. |
| High-confidence reading supports the selected type and every applicable check matches | 100% | All applicable automated matches passed; authenticity is unverified. |
| Readable evidence permits screening but either condition above is not established | 50% | Partial, ambiguous, unsupported-layout, or lower-confidence evidence needs review. |

No completed usable reading means `score = null`, with **Unable to assess**. Missing screening means **Not assessed**. Stale evidence means **Needs rescan**. These states are distinct from an assessed 0% and do not block application submission or the existing admin decision flow.

The overall score considers only the four required documents for the application currently being reviewed. Historical versions and supporting documents do not contribute. Use this order:

1. If any current, non-stale required document scores 0%, the overall score is 0%. Also show an incomplete-assessment indicator if another required document has no usable score.
2. Otherwise, if all four required documents have current assessed scores, use their minimum: 100% when all four are 100%, or 50% when at least one is 50%.
3. Otherwise, show **Assessment incomplete**, no overall percentage, and the available per-document results.

Examples: `[100, 100, 100, 100]` gives 100%; `[100, 50, 100, 100]` gives 50%; `[100, 0, 100, 100]` gives 0%; `[100, null, 100, 100]` is incomplete; and `[0, null, 100, 100]` gives 0% plus an incomplete indicator. Never average away a flagged document.

## Laravel boundary and persistence

Add one nullable `ocr_screening` JSON attribute to `shop_documents`, with an explicit model cast. A separate verification table, owner-level cached score, or new approval status is unnecessary. Existing rows remain nullable without a data backfill.

The optional request envelope is keyed by the four canonical logical slots and contains only known check outcomes, recognition confidence, and safe failure/reason codes. Bound it to four documents, a fixed check-key set per type, allowlisted reason codes, and a maximum of 32 KiB encoded for the complete envelope. Do not send or persist raw OCR text, extracted names, addresses, ID/TIN numbers, QR contents, or document images in this envelope.

Laravel validates the shape, types, enums, numeric bounds, slot/type association, and consistency of the envelope. It derives the displayed score from the validated evidence and the fixed scoring rules. Applicant-supplied scores, registration/document approval status, reviewer identity, and reviewer timestamps are prohibited inside the screening envelope and receive ordinary field validation errors. Existing top-level registration handling must continue to ignore unrecognized decision fields and set pending status explicitly. Missing metadata is allowed and remains unassessed; malformed or oversized metadata receives ordinary field validation errors, independently of the score.

Crucially, validation does not establish that browser check outcomes or confidence are truthful or derived from the uploaded image. The report's source is always `browser_tesseract`, with no verified/authenticated designation. All extracted semantic evidence remains applicant-controlled, even when Laravel calculates the final displayed number.

Persist a versioned report shape containing the source, assessment state, sanitized check outcomes, safe reason codes, and derived score. Bind it to the immutable document row and its server-calculated file checksum. Store a server-calculated comparison-context hash covering the relevant normalized application fields, selected document type, submitted date metadata, and scoring schema version. Derive the summary from these reports rather than saving a second aggregate.

Create reports inside the existing document-batch transaction. Preserve locking, replay handling, rollback, file cleanup, and atomic creation of the complete version set. An idempotent submission replay returns the existing report; it cannot overwrite a report by changing only its browser evidence.

## Replacement, resubmission, and historical results

For signed rejected-application resubmission, attach new results to the new pending document versions. Do not overwrite rejected or approved predecessor reports. Reuse a predecessor report only on the server when the same private file checksum, document type, comparison context, and report schema still match. Otherwise mark the new version unassessed until fresh browser evidence is available; retaining a file must not force an applicant to upload it again just to reach admin review.

At display time, an obsolete comparison context or a reviewer correction to document type/date metadata makes the old score stale. Preserve the original screening snapshot for its historical version, label it accordingly, and exclude it from the current aggregate. Do not silently present a previous document version's 100% as the current application's result. A report is a submission-time snapshot, so current document-validity warnings must remain visible alongside it.

## Admin experience and decision authority

Add **OCR match score - preliminary** to the existing registration list/details and a result beside each reviewed document. Show a concise source notice: **Browser screening; document authenticity is not verified.** Display the score/state, individual check results, and fixed human-readable reasons, such as **Business name differs**, **Expiry unclear**, or **Document type differs**.

Use existing badge, spacing, typography, and document-viewer patterns. Labels and icons must convey meaning without relying on color; progress text must be accessible. Keep detailed checks out of ordinary applicant flows. Do not add a new review dashboard, score-based sorting/filtering, or a new mandatory reviewer checkbox.

Continue using protected document routes. Only authorized reviewers receive the report details needed for registration review; no raw OCR, file path, checksum, or comparison hash is added to ordinary list payloads. Inspect originals through the existing viewer.

OCR must never mark a document viewed, fill reviewer-verified metadata as confirmed, write approval status, grant operational access, or invoke approve/reject. `ShopOwnerRegistrationDecisionService` remains the sole existing registration decision authority. Admins must still view every required private document and provide the existing reviewer metadata. They may decide applications at any score or without a score, subject to all existing validation and authorization rules.

## Acceptance and verification

The implementation is accepted when:

- All four required slots produce conservative type/detail checks using browser OCR, retaining DTI/SEC and current owner ID admission compatibility.
- Per-document and overall scores follow the tables above. Unassessed document states are nonnumeric; an incomplete overall assessment can show 0% only under the explicit flagged-document precedence rule. Missing evidence cannot imply fraud by itself.
- Valid 0%, 50%, 100%, failed-scan, omitted-screening, and unfinished-scan submissions can reach pending admin review.
- Browser metadata cannot set final approval/rejection, and Laravel never describes it as authenticated evidence.
- Replaced files, relevant form changes, late callbacks, and resubmission cannot reuse an unrelated/stale score; submission-key replays preserve the original report.
- Private access, existing approval metadata/view requirements, transaction rollback, document history, and explicit admin decisions remain enforced at every score.
- Customer OCR behavior and optional supporting-document behavior remain unchanged.

Focused frontend tests must cover the scoring cases, type/detail/date evidence, confidence bands, unsupported layouts, ID residential-address independence, OCR errors/timeouts, replace/remove/late-result handling, form changes, and nonblocking submission. Admin tests must cover labels/reasons, aggregate precedence, legacy/unassessed cases, current versus historical versions, and unchanged decision controls.

Laravel tests must cover envelope validation, missing evidence, prohibited screening decision fields, ignored top-level decision fields, private serialization, immutable report persistence, context invalidation, signed resubmission, replay, rollback, and explicit admin decisions after 0%, 50%, 100%, and unassessed submissions. Reuse these existing suites as regression coverage:

- `tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php`
- `tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php`
- `tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php`
- `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- `resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx`
- `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

The implementation plan must name new focused test files and run them before the affected regressions. Then run `pnpm run build` and scoped `git diff --check`; perform a browser smoke test for upload progress/failure, replacement, resubmission, and admin review when the application is runnable. No TypeScript compiler or lint pass may be claimed without actual configured tooling and execution.

No accuracy, fraud-detection, latency, or resource-use improvement has been measured. Such claims require later representative, permissioned evaluation; nominal 100% is only a categorical rule-match label.

## Design review record

Review mode: sequential under `AGENTS.md`; no subagents or parallel review were authorized.

Sequential specification review result: ready for written user review. Completeness, consistency, clarity, single-feature scope, and unnecessary-complexity checks passed. Standards review preserves the existing registration/document conventions; Spec review covers the approved decisions; risk review explicitly covers browser trust, unknown-versus-failed scoring, stale versions, private evidence, and admin-only decisions.

| Review gate for this documentation change | Result |
| --- | --- |
| Simplify | Pass: reuse the OCR reader and existing workflows; one report column; no additional approval system. |
| Standards, Spec, and risk | Pass: sequential review as recorded above. |
| Changed TypeScript quality | N/A: no TS/TSX changes. |
| Karpathy guidelines | Pass: assumptions are explicit, scope is confined to one spec, and acceptance criteria are testable. |
| Code splitting | N/A: no bundle changes. |
| Gauge improvements | Not measured; no empirical improvement claimed. |
| Security review | Design risks reviewed; implementation security checks are not run and remain required. |
| Verification before completion | Document hygiene and current-checkout references checked; application tests/builds are not run for this documentation-only change. |

Reuse audit: existing helpers, lifecycle services, decision service, viewers, and test suites are identified above. Dead-code scan: N/A for this new specification; no application code is removed. Vault learning: no implementation lesson is recorded at this design-only stage.

The implementation review stack remains required when code changes: simplify, sequential Standards/Spec/risk review, changed TypeScript quality, Karpathy guidelines, relevant bundle behavior, measured-claim review, Laravel/upload security, and fresh verification before completion. Reuse and dead-code checks apply to the implementation. This document does not claim those future gates have passed.
