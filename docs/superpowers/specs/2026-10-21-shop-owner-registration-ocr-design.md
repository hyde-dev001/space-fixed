# Shop-owner registration OCR screening design

Date: 2026-10-21. Refined on the same date after repository and design review.

## Status and decisions

The browser-only architecture and categorical scoring model are final and approved. This revision addresses the user's specification-refinement request and awaits approval before any implementation plan or application code is written.

Approved flow: Shop Owner Registration -> existing Document Upload -> browser Tesseract.js -> document recognition and matching -> preliminary screening report -> Laravel validation/persistence -> Super Admin manual review -> explicit approval or rejection.

The agreed decisions are:

- Reuse the customer's browser OCR approach for preliminary admin guidance.
- Assess document type and matching registration details, rather than document type alone.
- Show categorical 100%, 50%, or 0% match scores with reasons; these are not measured accuracy or authenticity probabilities.
- Allow otherwise valid applications at every score, including 0%, and when OCR fails or is unavailable.
- Keep every registration pending until an authorized admin explicitly approves or rejects it under the existing workflow.

Known limitation: an applicant can manipulate browser evidence. This is accepted within the approved scope; server OCR, a VPS, paid OCR, external document-verification APIs, and government integrations are excluded. Laravel validating a report's structure does not validate whether its contents are truthful.

## Outcome and scope

Help admins identify suspicious or inconsistent shop-owner registration documents without replacing their review. The feature provides triage evidence; it does not prevent a determined applicant from submitting false browser results.

Screen the four existing required logical slots:

1. `business_registration`, retaining both `dti_registration` and `sec_registration`;
2. `mayors_permit`;
3. `bir_certificate`, recognizing BIR Certificate of Registration / Form 2303; and
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

### Repository findings verified for this revision

Audit baseline: branch `fix/logistics-hardening-follow-up`, HEAD `7ba4d5b36ec509a0ddf9c04a2e99d5fe97d394cd`. CodeGraph was consulted first; the requirement and lifecycle services flagged as pending index sync were inspected directly. Existing unrelated working-tree changes are outside this task.

| Finding | Compatibility consequence |
| --- | --- |
| The registration page uses existing drop zones, original `File` objects, object-URL previews, `appendRegistrationDocuments`, and Inertia multipart submission. Resubmission uses the signed URL supplied by Laravel. | Extend this flow with optional evidence; preserve files, preview behavior, field names, submission keys, CSRF handling, and endpoint selection. |
| Registration exposes `first_name`, `last_name`, one `business_name`, and one `business_address`; it has no separate legal/trade-name or TIN field. `registration_type` is `individual` or `company`; DTI/SEC selection is independent. | Do not invent aliases, TIN comparisons, representative relationships, or a new Individual/Business-to-DTI/SEC restriction. A different, unlinked legal/trade name stays uncertain. |
| `ShopOwnerDocumentRequirementService::validateSubmission` requires exactly one DTI/SEC document and the other three slots, explicit expiration mode, and a dated Mayor's Permit. | Retain these rules. OCR is advisory and cannot relax mandatory metadata or introduce a new admission rule. |
| `ShopOwnerAuthController::register` and `resubmit` set pending status explicitly. Reapplications and signed rejected resubmissions use the existing limits and version-batch service. Changing DTI/SEC type on a retained file already requires a replacement. | Preserve both entry paths, replay behavior, retry limits, and replacement requirements. OCR is not a second registration flow. |
| `ShopDocumentLifecycleService::createPendingVersions` stages private UUID-named files, calculates SHA-256 checksums, locks the owner and slots, checks submission replays, and creates versions atomically with rollback cleanup. | Attach reports at version creation inside these transactions. Preserve lock ordering, file cleanup, and replay semantics. |
| `ShopDocument` has no `ocr_screening` attribute; current schema searches found no such field. Its private path/checksum are hidden. | The proposed nullable JSON column and cast have no identified naming conflict. Keep internal report/context data out of automatic model serialization. |
| The admin controller uses an explicit owner/document column selection, eager loading, and an allowlisted response. It currently returns document history. | Select any report/context inputs explicitly, keep internal hashes server-side, and aggregate only the current application candidates without changing document-view/review requirements. |
| `ShopOwnerRegistrationDecisionService` requires viewed private documents and reviewer metadata for pending candidates; it locks decisions, preserves audits/mail/provisioning, and separately enforces rejection reasons. | OCR cannot populate or bypass these requirements. Preserve decision guards, idempotency, and transaction behavior for approval and rejection at every score. |
| `app/Services/ShopDocumentValidityService.php` classifies pending/unreviewed versions as `metadata_unverified`; verified current versions have the existing expiry classifications. `app/Http/Controllers/PrivateSensitiveDocumentController.php` checks the privileged actor and document ownership. | An OCR pass cannot make pending metadata reviewer-verified, broaden viewer access, or replace the existing validity display. |
| The three customer helper files are absent in this checkout. Neither current `package.json` nor `pnpm-lock.yaml` declares Tesseract. | Reuse requires a small, deliberate source/dependency port during implementation, not an assumption that OCR already runs here. |
| Actual build configuration is `vite.config.js`, with Laravel entry `resources/js/app.jsx`, the `@` alias, existing manual chunks, and `emptyOutDir: true`. No OCR asset packaging was found. | Lazy loading and worker/core/language deployment need explicit coverage; do not assume bundling the API also deploys its runtime files. Protect unrelated generated-asset changes during future build checks. |

### Smallest reusable customer subset

The customer branch still resolves to `6540002bdfb0fd63d5f7f643f225343facc8ac77`. At that commit the three helpers and their tests exist under `resources/js/Pages/UserSide/Auth/`; `package.json` declares `tesseract.js` `^7.0.0`, with `tesseract.js` and `tesseract.js-core` resolved to `7.0.0` in its lockfile. These are source-reference facts, not installed dependency claims for this checkout.

| Source helper | Reuse and limits |
| --- | --- |
| `registrationOcr.ts` | Reuse full-image reading, image preparation, normalized confidence, bounded waits, and worker cleanup. The existing `readRegistrationId(file, onStage?, options?)` returns `{ text, confidence, qrDetected }`, calls `createWorker('eng')` without explicit asset paths, and returns the maximum confidence across concatenated OCR passes. It bounds individual operations and terminates a late-created abandoned worker, but does not expose caller cancellation or one total document deadline. Its preparation target does not itself cap large-image dimensions. |
| `registrationNameMatch.ts` | Reuse personal-name normalization, suffix handling, and existing supported variations, including its limited one-edit tolerance for longer tokens. Its `mismatch` can arise from incomplete matching, so it cannot by itself establish a 0% conflict. Do not apply personal-name tolerance to business names or numeric identifiers. |
| `registrationDocumentScreening.ts` | Reuse compatible pure ID-family indicators and confidence-band logic for National ID, driver's license, passport, and UMID. Its customer admission rejection signals, duplicate-side checks, and front/back requirements must not govern owner registration or business certificates. |

Port only the needed reader and pure matching/recognition logic plus relevant tests and the existing OCR dependency if still absent at implementation time. Do not blindly merge the customer feature branch, copy its persistence/workflow, add another OCR engine, or change customer defaults. Certificate reading uses the full image with ID-specific cropping disabled. Preserve current owner ID acceptance, including plausible IDs not recognized by the customer's limited family list.

Implementation assumptions requiring verification in the later plan: owner screening needs opt-in localized word/line evidence, owner-scoped cancellation/deadline handling, and explicit local asset paths from the same Tesseract reader. Add only compatible capabilities while retaining customer call signatures/default behavior and tests. Keep pass-local text, position, and confidence associated; do not use the best pass's confidence for words from another pass. If localized evidence is absent, the affected check remains uncertain. Tesseract documents explicit structured output options in its [recognition API](https://github.com/naptha/tesseract.js/blob/master/docs/api.md#worker-recognize); this is not a new framework or a measured accuracy claim.

## Browser flow

1. Validate the selected file using the existing local file checks, retaining the original upload and current preview behavior.
2. Import the OCR module on demand for a required document. Process one document at a time, with at most one active OCR worker for this page. Show accessible loading/reading progress.
3. Keep extracted text and localized evidence in browser memory only. Recognize the document and compare it with current form values under the matrix below.
4. Show **Check complete**, **Needs admin review**, or **Unable to assess** to the applicant. Detailed scores/reasons are for Super Admin review, not new mandatory applicant actions.
5. Send original files through the existing registration or signed resubmission request, with any complete, valid optional screening evidence available at submission time.

### Lifecycle contract

| Event | Required behavior |
| --- | --- |
| Select or queue a file | Associate work with its logical slot and selection generation. Keep only the latest queued selection per slot; never start concurrent scans. OCR does not start for optional supporting documents. |
| Worker startup or reading | Retain the reader's 60-second operation limit, plus a 60-second total budget per document, so multiple preparation/recognition operations cannot multiply the wait. A deadline ends the document's eligible result. Continue the queue only after its worker is confirmed stopped. No automatic retry loop is required. |
| Timeout, worker failure, unsupported browser, unavailable assets, or no usable text | Show **Unable to assess** with a safe reason. Terminate/discard the worker and clear timers. If startup/termination is still unresolved and safe cleanup cannot be confirmed, do not create another worker; mark remaining queued work unavailable for this attempt and keep submission available. Terminate any late-created abandoned worker. |
| Replace or remove a file | Immediately clear its evidence, increment its generation, discard superseded queued work, and cancel/terminate superseded active work where possible. Ignore all late callbacks for an old generation. Preserve existing preview URL cleanup and never upload a processed image in place of the original. |
| Relevant form or document-metadata change | Invalidate affected check results immediately. Recalculate against current values using retained text/localized evidence; do not reread an unchanged image unnecessarily. Business-type selection, date metadata, and the selected DTI/SEC type are part of the affected context; unrelated email/phone edits need not trigger OCR. |
| Submit during a scan | Take a snapshot of currently usable reports. Omit unfinished, stale, or invalid report entries. Do not wait for OCR, attach partial work as a pass, or let a later callback mutate the submitted evidence. There are no OCR-only writes after submission. |
| Optional report construction/serialization fails | Omit the optional entry, or the entire envelope if necessary, and submit the otherwise valid application. A recoverable client OCR failure must not trap the applicant in a validation loop. |
| Existing registration validation rejects the request | Retain usable in-memory evidence only for unchanged files/context while the page stays mounted. Continue honoring existing errors and validation; do not suppress server errors. |
| Unmount or successful completion | Invalidate all generations, stop queued work, clear timers and text/evidence references, terminate active workers, and release OCR-created bitmaps/canvases/object URLs. Terminate a worker that resolves after startup has already been abandoned. Do not set component state after unmount. |

Only OCR model/asset caching may persist in the browser under the engine's supported behavior. Do not put uploads, extracted text, field values, screening reports, or sensitive scan diagnostics into localStorage, IndexedDB, application caches, analytics, or logs. The original upload remains available to existing form handling for as long as that flow needs it.

The 5 MB upload limit does not bound decoded pixel memory. Owner OCR must use a bounded working image, using the reader's existing 1,800-pixel long-edge target as a cap for recognition preparation rather than an upscale-only target. Preserve the original file. Avoid unnecessary ID-specific passes for certificates; if preparation is unsafe/unavailable, skip OCR for that image. This bounds OCR working-image dimensions, not the memory used by an initial browser decode or the existing preview; actual peak device memory is unmeasured.

### Worker, core, language assets, and Vite

Use versioned, locally served worker/core/language files for owner OCR, deployed with SoleSpace. Set explicit same-origin `workerPath`, a compatible core directory, and `langPath`; include required core variants and English trained data. Do not add a runtime CDN fallback. These paths are supported by Tesseract's [local-installation documentation](https://github.com/naptha/tesseract.js/blob/master/docs/local-installation.md).

The later implementation plan must choose exact emitted or public asset paths against the resolved package and existing Vite deployment; no asset files are created in this task. Resolve application/base URLs correctly instead of hardcoding localhost or a development node_modules path. Deployment must include the worker, selected core JavaScript/WASM resources and language data, preserve compatible versions, and avoid losing them to `emptyOutDir`. Use versioned URLs to avoid mixed cached releases. Do not change customer asset resolution silently.

Verify lazy loading in the production build and test cold-cache startup, required resource responses and MIME/compression handling, supported core selection, the deployed base path, and existing CSP worker/WASM policy where applicable. Missing resources or blocked execution must degrade to **Unable to assess**, with no external document upload or registration blockage. Production asset availability is not verified by this specification audit.

## Evidence and matching rules

Each applicable check is exactly `match`, `mismatch`, or `uncertain`. Fixed rules determine applicability; the browser cannot declare a required check inapplicable. Extraction happens in memory. The values described in these matrices are not extra persisted or transmitted screening fields.

### Document-specific evidence matrices

#### DTI business registration

| Evidence dimension | Requirement |
| --- | --- |
| Document-type indicators | DTI/Department of Trade and Industry issuer evidence, a business-name-registration certificate heading, and labelled registration/certificate structure. Require multiple independent signals; a keyword, filename, logo, or number alone cannot establish type. |
| Fields to extract | Labelled registered business/trade name, proprietor where identified, registration/certificate reference as supporting type evidence, and applicable issue/expiry fields. |
| Registration comparisons | Compare the explicit registered business/trade field with `business_name`. Compare an explicitly identified sole proprietor with `first_name`/`last_name`; do not confuse a signatory with the proprietor. |
| Minimum matching evidence | Reliable type indicators and complete, localized, relevant name fields; applicable date checks must also match. Require the identified proprietor check for a recognized sole-proprietor DTI certificate. |
| Confident mismatch | Reliable evidence establishes SEC/another incompatible type instead of selected DTI, or a complete explicitly equivalent business/proprietor field conflicts after safe normalization. |
| Uncertainty | Missing/partial labels or values; confusion between legal, registered, and trade names; unknown proprietor relationship; suspect OCR characters or unclear field positions. A different unlinked legal name is not itself a conflict. |
| Dates | Use the common date rules below. Do not invent an annual renewal rule from OCR. |
| Unsupported layout | Recognizable but insufficiently understood DTI-like content remains uncertain; no single template, color, or logo is required. |

#### SEC business registration

| Evidence dimension | Requirement |
| --- | --- |
| Document-type indicators | SEC/Securities and Exchange Commission issuer evidence, a relevant registration/incorporation certificate heading, and labelled entity/registration structure. |
| Fields to extract | Legal/registered entity name, explicit trade-name linkage if present, registration reference as supporting evidence, and applicable dates. |
| Registration comparisons | Compare a clearly equivalent entity/business/trade name to the single `business_name`. No corporate-representative name comparison is required by this certificate. Preserve the current independent DTI/SEC and Individual/Business selections. |
| Minimum matching evidence | Reliable independent type signals and an unambiguous matching entity/business identity, plus matching applicable dates. |
| Confident mismatch | Reliable incompatible document type, or a complete equivalent business-identity field clearly conflicts. |
| Uncertainty | A legal entity name differs from the entered trade name without explicit linkage; a representative is absent; name role/labels are ambiguous. Absence of the registering representative is not a mismatch or missing required check. |
| Dates | Use common rules; apply issue/expiry comparison only where relevant to this document's declared metadata/type. |
| Unsupported layout | Plausible SEC registration content without sufficient supported structure remains uncertain and eligible for manual review. |

#### Mayor's Permit / Business Permit

| Evidence dimension | Requirement |
| --- | --- |
| Document-type indicators | Business/Mayor's Permit heading, an identifiable issuing city/municipality/LGU, and labelled permit/registration-reference structure. A generic government heading alone is insufficient. |
| Fields to extract | LGU, permit reference, business/trade name, business address, and applicable issue/validity/expiry dates. |
| Registration comparisons | Compare the explicit business/trade name and business-premises address with `business_name` and `business_address`, plus applicable submitted dates. A proprietor name can support identity but cannot substitute for an unclear business field. |
| Minimum matching evidence | Reliable permit/LGU/reference signals and complete matching business identity, business address, and required expiry evidence. |
| Confident mismatch | Reliable wrong type, conflicting equivalent business identity, clearly different premises/locality in the labelled business address, or failed applicable dates. |
| Uncertainty | Different LGU layout, unclear address components, a name with an unconfirmed role, obscured permit/date text, or ambiguous date formats. A matching city alone is not a complete address match. |
| Dates | Preserve the existing mandatory `dated` expiration mode; assess the labelled expiry/valid-through field and optional issue date under common rules. |
| Unsupported layout | Accept layout variation across LGUs. Do not demand fixed colors, logos, positions, or one city's template. |

#### BIR Certificate of Registration / Form 2303

| Evidence dimension | Requirement |
| --- | --- |
| Document-type indicators | BIR/Bureau of Internal Revenue issuer evidence, certificate-of-registration/Form 2303 identifiers, and relevant labelled taxpayer/business/address structure. A TIN-like number alone cannot establish type or authenticity. |
| Fields to extract | Taxpayer/registered name, explicitly labelled business/trade name, registered address, certificate/registration references as type support, and applicable dates. Numbers remain memory-only. |
| Registration comparisons | Compare the explicit business/trade identity and registered business address with existing business fields. If a sole proprietor is explicitly identified, compare that taxpayer role with the applicant name; do not require a company taxpayer to equal its registering representative. No TIN comparison is possible because registration has no TIN field. |
| Minimum matching evidence | Reliable Form 2303/type signals, equivalent business identity, registered business address, any applicable explicitly identified proprietor, and applicable date checks. |
| Confident mismatch | Reliable different certificate/type or a complete, comparable business/proprietor/address field conflicts; applicable dates fail. Do not call a different corporate taxpayer name an applicant-name mismatch. |
| Uncertainty | Taxpayer versus trade-name role is unclear, the business name is absent/obscured, or legal/trade-name linkage or address mapping cannot be established. |
| Dates | Form 2303 is not assigned an automatic annual expiry. Apply common rules to actual relevant fields and submitted metadata. |
| Unsupported layout | Plausible but unsupported Form 2303 layout yields uncertainty rather than a fraud finding. |

#### Government-issued Valid ID

| Evidence dimension | Requirement |
| --- | --- |
| Document-type indicators | Compatible customer ID-family issuer/title/structure indicators for the currently supplied image. Keep the existing owner one-image contract; do not import mandatory back-side, QR, or MRZ requirements that need another upload. |
| Fields to extract | Localized holder-name fields and applicable identification/date fields. ID-number structure may support recognition but is not matched to a new form field or officially verified. |
| Registration comparisons | Compare holder identity with `first_name` and `last_name`, respecting existing supported personal-name variations. Do not compare a residential address with the shop address. |
| Minimum matching evidence | Reliable recognized-family signals, a complete matching holder-name field, and applicable date evidence. Name-helper output alone is insufficient without localized field support. |
| Confident mismatch | Reliable non-ID/incompatible content or a complete, clearly identified holder field conflicts, or an applicable date fails. A partial-name helper `mismatch` is not sufficient. |
| Uncertainty | An unfamiliar but plausible government ID, unsupported field structure, a missing side needed to read a field, obscured/partial names, or weak/conflicting readings. Preserve admission of currently accepted owner IDs. |
| Dates | Compare applicable expiry/issue metadata; support recognized non-expiring types without inventing an expiry date. |
| Unsupported layout | Use 50% when readable but not confidently classifiable; use Unable to assess when there is insufficient usable reading. Do not equate an unsupported family/layout with fraud. |

### Required checks and reliable evidence

| Slot/type | Required check keys |
| --- | --- |
| DTI | `document_type`, `business_identity`, `proprietor_identity`, `dates` |
| SEC | `document_type`, `business_identity`, `dates` |
| Mayor's Permit | `document_type`, `business_identity`, `business_address`, `dates` |
| BIR | `document_type`, `business_identity`, `business_address`, `dates`; `proprietor_identity` additionally when a supported, explicit sole-proprietor taxpayer field is present |
| Valid ID | `document_type`, `owner_identity`, `dates` |

For conditional BIR proprietor evidence, use a bounded relationship indicator: `sole_proprietor`, `corporate`, or `unclear`. A clear supported relationship determines applicability; an unclear role keeps `business_identity` uncertain. The server validates this consistency but cannot establish that the browser identified the role truthfully. Missing required checks normalize to uncertain; they can never yield 100% by omission.

Use normalized confidence bands high >= 0.85, medium >= 0.65, otherwise low, preserving the customer's scale. These are heuristic recognition bands, not calibrated fraud probabilities. Evaluate confidence for the actual words/field or type signals used by each check, together with its complete value, field association, and unambiguous interpretation. Every material token must have sufficient local evidence for a reliable match/conflict; averaging away a weak token is not sufficient. Missing localized confidence or ambiguous field association leaves that check uncertain.

Document-level recognition confidence is informational and must never certify every field. A high document confidence with an unclear holder name or date cannot yield 100% or a conflict for that field. A reliable critical conflict can yield 0% even when unrelated parts of a page read poorly. Medium/low-confidence field reading alone cannot establish a definitive match or mismatch. Preserve each OCR pass's evidence; contradictory passes leave the affected field uncertain instead of selecting the highest-confidence pass blindly.

### Normalization and false-conflict prevention

Normalize case, repeated whitespace, punctuation, and conservative formatting differences. Reuse existing supported personal-name ordering, suffix/diacritic handling, and variations; do not expand them into unrestricted approximate matching or add a fuzzy dependency. Compare a field with its appropriate role, not arbitrary text anywhere on a page.

Treat `O`/`0`, `I`/`1`, broken words, poor resolution, obscured characters, missing labels, ambiguous dates, and unclear addresses as possible recognition errors. Do not silently rewrite every letter/digit or repair a number into a match. Where the correct interpretation is not supported by localized evidence, return uncertain. Missing or poorly recognized text is never a confirmed mismatch.

Legal entity, registered business, trade name, proprietor, corporate representative, and signatory are distinct roles. Match equivalent roles or explicit linkage. The one existing business-name field is not a complete legal/trade-name registry; absence of linkage means uncertainty, not a fabricated alias or automatic conflict. A known address abbreviation may normalize conservatively, but a partial address or matching city alone cannot prove the full business-address check.

### Common date rules

`dates` is always a check, with applicability within it determined by known document policy and existing `expiration_mode`, `issued_on`, and `expires_on`. For a dated document, require reliably read, unambiguous expiry/valid-through evidence and compare it with submitted metadata. Compare issue date when provided, and check any confidently identified issue/expiry range for impossible ordering, future issue, or expiry before the reference day. Ambiguous day/month ordering is uncertain unless the document resolves it.

For a recognized non-expiring document with `expiration_mode = none`, do not require a printed expiry or invent one. Unknown expiry policy/layout or an unreadable applicable date remains uncertain. Do not infer annual BIR expiry. A reliably extracted conflicting date, expired date, future issue date, or reversed range is a mismatch; a failed OCR read is not.

Use a server-provided reference calendar day and the existing shop timezone policy rather than relying on the applicant device clock. If a reference day cannot be used safely, leave affected temporal checks uncertain. Date findings are submission-time snapshots. Preserve `ShopDocumentValidityService` and its `metadata_unverified` result for pending versions; a screening pass never constitutes reviewer confirmation. Continue showing original submitted dates and current server validity/expiry warnings without presenting them as independently verified OCR evidence.

## Exact scoring

For a completed per-document assessment, apply these rules in order:

| Condition | Score | Admin explanation |
| --- | --- | --- |
| At least one required check has reliable localized conflicting evidence under the matrices | 0% - Conflicting Evidence Detected | Flag the specific document/field/date conflict; the application still needs an admin decision. |
| Every applicable required check has sufficient reliable localized evidence and matches | 100% - Automated Checks Passed | All required automated matches passed; authenticity is unverified. |
| Readable evidence permits screening but neither condition above is established | 50% - Requires Further Review | Partial, ambiguous, unsupported-layout, or lower-confidence field evidence needs review. |

No completed usable reading means `score = null`, with **Unable to assess**. Missing screening means **Not assessed**. Stale evidence means **Needs rescan**. These states are distinct from an assessed 0% and do not block application submission or the existing admin decision flow.

The overall score considers only the four required documents for the application currently being reviewed. Historical versions and supporting documents do not contribute. Use this order:

1. If any current, non-stale required document scores 0%, the overall score is 0%. Also show an incomplete-assessment indicator if another required document has no usable score.
2. Otherwise, if all four required documents have current assessed scores, use their minimum: 100% when all four are 100%, or 50% when at least one is 50%.
3. Otherwise, show **Assessment incomplete**, no overall percentage, and the available per-document results.

Examples: `[100, 100, 100, 100]` gives 100%; `[100, 50, 100, 100]` gives 50%; `[100, 0, 100, 100]` gives 0%; `[100, null, 100, 100]` is incomplete; and `[0, null, 100, 100]` gives 0% plus an incomplete indicator. Never average away a flagged document.

## Laravel boundary and persistence

Retain one nullable `ocr_screening` JSON attribute on `shop_documents`, with an explicit cast and controlled serialization. Existing rows remain null without a backfill. There is no concrete schema incompatibility identified, and no need for another table, cached owner-level score, or approval state. A migration is a future implementation deliverable, not part of this refinement task.

The optional multipart field `ocr_screening` is a UTF-8 JSON object keyed by the four canonical logical slots. Limit the complete encoded envelope to 32 KiB (32,768 bytes), four entries, the fixed check keys above, and allowlisted states/reasons. Do not count uploaded file bytes toward this envelope limit or change existing request/file limits. A missing entry is unassessed and does not invalidate the application.

Per entry, allow only assessment state, the known relationship indicator where relevant, reference day, an optional document recognition-confidence summary, fixed check outcomes with localized confidence/evidence flags, and safe reason codes. Confidence is finite and normalized to 0..1; the localized reliability flags describe complete value, field association, independent type signals, and unambiguous interpretation as applicable. They are semantic browser claims, not transmitted values or verified facts. Laravel derives which checks are required and their eligibility for scoring; a syntactically valid but insufficient check is uncertain rather than silently accepted as a reliable match/conflict.

The fixed entry contract is:

| Field | Allowed structure |
| --- | --- |
| `assessment_state` | `assessed` or `unable_to_assess`; absent entries are Not assessed. Needs rescan is derived from context changes, not an applicant assertion that alters history. |
| `detected_family` | A known certificate/ID family from the matrices, `other_document`, or null for unresolved recognition. Unsupported IDs use unresolved evidence and retain admission compatibility. |
| `relationship` | `sole_proprietor`, `corporate`, or `unclear` for BIR evidence; omitted where inapplicable. |
| `reference_day` | Server-provided calendar day in `YYYY-MM-DD` form, required for a usable temporal check; no client-controlled reviewer timestamp. |
| `recognition_confidence` | Optional finite number in 0..1, for the document summary only. |
| `checks` | Map using only the required/applicable check keys above. Each check has `result`, nullable localized `confidence` in 0..1, and fixed boolean flags `complete_value`, `field_associated`, `unambiguous`, and `independent_type_signals`. Apply flags relevant to that check; the last flag is required for reliable type recognition. |
| `reason_codes` | At most eight distinct codes from the fixed vocabulary below; no free-text messages. |

For a semantic field match/conflict, require sufficient localized confidence (high band) and the applicable complete/associated/unambiguous evidence flags; otherwise normalize that result to uncertain. Known non-expiring policy uses reliable recognized-type evidence and `expiration_mode = none`, not a fabricated expiry value/confidence. Reject contradictory structure such as `unable_to_assess` accompanied by eligible matching/conflicting checks. Browser and Laravel must share equivalent fixed scoring fixtures for these normalization cases.

Use these reason codes: `wrong_document_type`, `business_identity_conflict`, `owner_identity_conflict`, `proprietor_identity_conflict`, `business_address_conflict`, `date_conflict`, `uncertain_field`, `low_recognition_confidence`, `unsupported_layout`, `ocr_unavailable`, `ocr_failed`, `ocr_timeout`, and `scan_unfinished`. Derive stale-context labels on display instead of accepting a browser command to modify stored history. Map codes to fixed UI text; never render applicant-supplied reason text. Unknown keys, states, codes, nonfinite/out-of-range numbers, or invalid JSON are validation errors. Missing required checks cannot produce a pass. Bind reference-day interpretation to server policy; a date rollover may downgrade temporal evidence without blocking registration.

Do not send or persist raw OCR text, word boxes/word text, extracted names, addresses, ID/TIN numbers, QR contents, or images in the screening JSON. The existing registration form still sends its required applicant/business fields normally. Avoid logging the envelope or extracted text, including in validation diagnostics.

Laravel validates the shape, types, bounds, slot/type association, and structural consistency, then derives the score using the same fixed rules as the browser. Document recognition confidence is not a shortcut for missing localized evidence. Applicant-supplied scores, registration/document approval status, reviewer identity, and reviewer timestamps are prohibited inside this envelope and receive ordinary validation errors. Existing top-level handling continues ignoring unrecognized decision fields and setting pending explicitly. Malformed metadata actually submitted receives field-specific validation errors; the normal frontend instead omits metadata it cannot produce/serialize safely. Existing mandatory validation stays authoritative.

Crucially, validation does not establish that browser check outcomes or confidence are truthful or derived from the uploaded image. The report's source is always `browser_tesseract`, with no verified/authenticated designation. All extracted semantic evidence remains applicant-controlled, even when Laravel calculates the final displayed number.

Persist a versioned report shape containing the source, reference day, assessment state, sanitized evidence/check outcomes, safe reason codes, and server-derived score. Associate it with the immutable document version and server-calculated file checksum; store a server-calculated comparison-context hash for the relevant normalized application fields, selected type, submitted date metadata, and scoring schema. The hash detects later context changes; it does not prove the browser evaluated those fields or the image honestly. Keep it internal. Use existing record timestamps for recording time, and derive the summary rather than saving a second aggregate.

Create reports inside the existing document-batch transaction. Preserve locking, replay handling, rollback, file cleanup, and atomic creation of the complete version set. An idempotent submission replay returns the existing report; it cannot overwrite a report by changing only its browser evidence.

## Replacement, resubmission, and historical results

For signed rejected-application resubmission, attach new results to the new pending document versions. Do not overwrite rejected or approved predecessor reports. Reuse a predecessor report only on the server when the same private file checksum, document type, comparison context, and report schema still match. Otherwise mark the new version unassessed until fresh browser evidence is available; retaining a file must not force an applicant to upload it again just to reach admin review.

At display time, an obsolete comparison context or a reviewer correction to document type/date metadata makes the old score stale. Keep draft reviewer edits local to that review's display; do not persist a replacement report or treat a canceled draft as a submitted change. Preserve original snapshots, label historical versions, and exclude them from the current aggregate. Current candidates are the pending non-current versions for an undecided application; completed applications show their latest reviewed set and clearly historical reports, without mixing predecessor scores into a new submission.

A report is a submission-time snapshot, not a promise of validity on the later review date. Label its reference day and preserve it if a date subsequently passes; do not rewrite history or erase another confirmed conflict because the calendar changed. Existing server validity/expiry indicators and unverified submitted date fields remain visible alongside the dated screening snapshot. Do not present a historical 100% date-pass as independently verified current validity. OCR does not alter verification state or final decisions, and mere passage of a day does not require rereading an unchanged image.

## Admin experience and decision authority

Use **OCR Match Score — Preliminary** on the existing registration review experience, with a result beside each document. Show: **Browser-based document screening. Results are preliminary and document authenticity has not been independently verified.** Display overall score/state, per-document results with reference day, uncertain fields, potential conflicts, and fixed reasons such as **Business name differs**, **Expiry unclear**, or **Document type differs**. Show recognition confidence only where it aids review, separately labelled from the categorical score and without implying all fields share that confidence.

Use existing badge, spacing, typography, and document-viewer patterns. Labels and icons must convey meaning without relying on color; progress text must be accessible. Keep detailed checks out of ordinary applicant flows. Do not add a new review dashboard, score-based sorting/filtering, or a new mandatory reviewer checkbox.

Continue using protected document routes. Only authorized reviewers receive the report details needed for registration review; no raw OCR, file path, checksum, or comparison hash is added to ordinary list payloads. Inspect originals through the existing viewer.

OCR must never mark a document viewed, fill reviewer-verified metadata as confirmed, write approval status, grant operational access, or invoke approve/reject. `ShopOwnerRegistrationDecisionService` remains the sole existing registration decision authority. Admins must still view every required private document and provide the existing reviewer metadata. They may decide applications at any score or without a score, subject to all existing validation and authorization rules.

## Acceptance and verification

Use representative, permitted, redacted document fixtures and synthetic failure cases. Keep personal values in fixtures only where safely synthetic; do not commit real unredacted documents. These are functional expectations, not measured production OCR accuracy.

| Acceptance ID | Required, testable evidence during implementation |
| --- | --- |
| A1 - Recognition | Correct DTI, SEC, Mayor's Permit, Form 2303, and compatible government-ID fixtures exercise every required check. Selected DTI with reliably recognized SEC/another wrong category is a conflict. At least two different LGU permit layouts and multiple supported ID layouts are accepted by recognition rules without a fixed template. Plausible unsupported layouts yield uncertainty or no usable assessment, never 0% from unsupported status alone. |
| A2 - Roles and matching | Matching legal/registered/business names pass when equivalent or explicitly linked; an unlinked legitimate trade-name difference stays uncertain. Test sole proprietor versus corporate representative, signatory confusion, definite equivalent-field name conflict, clear address conflict, unclear/partial address, and independence of ID residential/shop address. No new name/TIN field or Individual/Business policy is required. |
| A3 - Recognition errors | `O`/`0`, `I`/`1`, broken words, missing/obscured fields, poor resolution, and ambiguous labels/dates cannot become a confirmed conflict merely from absence or a helper's partial mismatch. High document confidence with an uncertain field cannot give 100%; low/medium localized evidence cannot establish a definite field result. |
| A4 - Dates | Cover expired date, future issue, reversed range, conflicting submitted date, ambiguous day/month order, and recognized non-expiring documents including Form 2303 without an invented annual expiry. Preserve mandatory dated Mayor's Permit metadata and `metadata_unverified` for pending versions. |
| A5 - Scoring | Unit/contract tests cover every 100%/50%/0% rule, all four nonnumeric labels, all five approved aggregate examples, conflict precedence with incomplete documents, missing checks, and recognition-confidence boundaries. Browser and Laravel fixed evidence/scoring cases must agree. |
| A6 - Nonblocking admission | An otherwise valid application reaches pending review at 100%, 50%, 0%, no usable OCR, missing assets, startup/worker failure, timeout, unsupported browser, omitted metadata, frontend serialization failure, and submission during an unfinished scan. Explicit malformed/oversized metadata receives validation errors. Existing file/email/date/location/security failures remain failures. |
| A7 - Browser lifecycle | Test sequential processing with no more than one active worker; latest queued file only; replacement/removal, slow scans, 60-second deadline, late callbacks, form/metadata changes, worker startup resolving after abandonment, unresolved-cleanup queue fallback, unmount cleanup, and no post-submit evidence mutation. Recalculate retained text without an extra recognition call for an unchanged file. Bound OCR working-image dimensions without changing uploaded files or admission constraints. |
| A8 - Deployment | Future production build and browser checks demonstrate lazy loading, local worker/core/language resolution under the deployed base URL, cold-cache operation, and safe failure when a required asset or browser capability is unavailable. Record actual requested asset paths, availability, and any relevant CSP compatibility; do not assume present deployment readiness. |
| A9 - Persistence | Test 32,768-byte envelope boundary, fixed keys/codes, bounded confidence, missing versus malformed reports, prohibited score/approval/reviewer fields, ignored top-level decision fields, conditional-check normalization, server-owned checksum/context binding, immutable reports, submission replay, transaction rollback/file cleanup, and legacy rows with no report. |
| A10 - Version isolation | Signed rejected resubmission and rejected reapplication cover replaced files, retained matching reports, changed comparison details, DTI/SEC replacement constraints, different schema/context, reviewer corrections, and historical isolation. Later expiry retains a dated snapshot plus current validity/date warnings without rewriting the report or dropping an unrelated confirmed conflict. No context-stale snapshot becomes the current aggregate. |
| A11 - Manual authority and privacy | For each 0%, 50%, 100%, and unassessed case, test explicit authorized approval and explicit authorized rejection under existing rules. Approval without required viewed flags or reviewer metadata still fails, rejection still needs its reason, and unauthorized/cross-shop document access still fails. Reports cannot approve/reject, mark viewed, grant access, expose hashes/raw OCR/extra personal values, or broaden viewer rights. |
| A12 - Regressions | Preserve customer helper defaults, customer OCR regression tests on any implementation branch containing them, original uploads and accepted owner ID types, optional supporting documents, email/location/session behavior, retry limits, existing decision/audit/mail/provisioning semantics, and existing document history. |

Reuse these existing current-checkout suites, adding focused cases later:

- `tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php`
- `tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php`
- `tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php`
- `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- `resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx`
- `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

The future implementation plan must name focused frontend/Laravel checks and regression commands using `pnpm`, followed by `pnpm run build`, scoped `git diff --check`, and browser verification where runnable. Customer helper/test paths on the pinned branch are reference-only until ported or present in the implementation branch; do not claim they ran here. No compiler or lint pass may be claimed without configured tooling and actual execution. No application test/build or OCR benchmark is run for this documentation-only revision.

No accuracy, fraud-detection, latency, or resource-use improvement has been measured. Such claims require later representative, permissioned evaluation; nominal 100% is only a categorical rule-match label.

## Risk register

| Risk | Mitigation or accepted limit |
| --- | --- |
| Browser evidence manipulation | Accepted limitation. Preliminary/unverified labels, fixed Laravel score derivation, strict bounded structure, protected original viewer, and unchanged manual decisions. Shape validation, hashes, and browser fingerprints do not prove authenticity or truthful OCR. No server OCR or new infrastructure is proposed. |
| OCR misclassification | Multiple independent type signals, document-specific structure, supported-layout fixtures, and uncertainty for unfamiliar layouts. Recognition does not establish genuine government issuance. |
| False name/address/date conflicts | Field-local reliability, role-aware comparisons, conservative normalization, explicit trade/legal-name uncertainty, and ambiguity tests. Document confidence and incomplete name-helper mismatches cannot decide a field. |
| Privacy | Original files stay in existing private storage; extracted text/word evidence stays in browser memory; bounded reports contain no extracted personal values. Exclude raw OCR, internal hashes and report internals from ordinary serialization/logs; preserve viewer authorization. |
| Stale or mixed-version reports | Selection generations, submission snapshots, context/schema/file binding, immutable version records, and current-candidate-only aggregation. Label the assessment reference day and show current validity/date warnings separately; never present a dated screening snapshot as proof of current validity. |
| Browser CPU/memory use | Lazy loading, sequential work, bounded latest-only queue and OCR working images, deadlines, and confirmed cleanup before another worker. Fall back if safe worker cleanup cannot be confirmed. Initial decode/preview memory and real device resource use remain unmeasured; the upload-size limit is not a decoded-pixel guarantee. |
| Asset/deployment failure | Explicit compatible local asset paths, versioned releases, production/cold-cache checks, base-URL and worker/WASM policy checks. Failures degrade to Unable to assess while valid registration remains available. Deployment compatibility is not yet proven. |
| Registration/review regressions | Keep existing fields, validation, upload/CSRF/endpoints, version transactions, replay, retry limits, customer behavior, admin review payloads, and decision authority. Optional metadata omission handles client failure; malformed submitted metadata still fails validation. |

## Meaningful changes from the original October 21 specification

1. Marked browser-only architecture and its trust limitation as settled, separating approved requirements, audited facts, and later implementation assumptions.
2. Replaced historical reuse assumptions with verified checkout/branch/dependency findings, actual interfaces, the name-helper false-conflict risk, and the reader's missing localized confidence.
3. Added five document-specific evidence matrices and fixed check sets, with explicit proprietor/representative, legal/trade-name, LGU variation, Form 2303, and existing ID-acceptance rules.
4. Replaced document-wide confidence shortcuts with localized, complete, role-aware evidence; specified OCR character/date ambiguity and conservative normalization.
5. Kept all categorical scores and aggregate examples while making named score meanings, missing-check handling, and nonnumeric states explicit.
6. Added precise worker/queue/deadline/cleanup and submission-snapshot behavior, unresolved-cleanup fallback, bounded OCR working images, optional metadata omission, and a local asset/Vite deployment requirement.
7. Clarified the bounded JSON contract, allowlists and server-derived scores, privacy limits, conditional evidence, context-hash limitations, immutable replay/resubmission handling, pending validity, and dated snapshots alongside current expiry warnings.
8. Added the risk register and acceptance matrix, including both admin approval and rejection at every score, customer regressions, permitted fixtures, and deployment failure tests.

## Sequential review record

Review mode: sequential under `AGENTS.md`; no subagents or parallel review were authorized.

Sequential review completed in order: repository Standards, the user's refinement requirements/approved design, then correctness/security/compatibility risks. Result: ready for user approval before implementation planning, with no unresolved specification-blocking findings. This is a main-agent design review, not independent or parallel verification.

| Review area | Result and evidence |
| --- | --- |
| Completeness | Pass: repository findings, five evidence matrices, fixed checks/score rules, lifecycle/assets, bounded persistence, admin authority, risks, acceptance cases, and approval checkpoint cover the refinement request. |
| Consistency and clarity | Pass: absent/uncertain evidence differs from a confirmed conflict; document confidence does not qualify every field; nonblocking client fallback is distinct from validation of malformed submitted metadata. Dated snapshots do not erase conflicts or claim current authenticity. |
| Scope and simplicity | Pass: one revised specification; one reused OCR engine and one proposed report column; existing uploads/review remain authoritative. No implementation plan, new application code, new infrastructure, or extra workflow is delivered. |
| Security | Pass at design level: browser manipulation remains explicitly accepted; fixed structural validation does not claim truth verification; no score controls final decisions, document-view requirements, or operational access. Private evidence and report serialization limits are explicit. |
| Compatibility | Pass at specification level against inspected sources: independent DTI/SEC selection, one-image ID acceptance, existing date rules, pending validity, both resubmission entry paths, replay/transaction behavior, and customer defaults are preserved. Runtime integration/asset availability still require future verification. |

| Review gate for this documentation change | Result |
| --- | --- |
| Simplify | Pass: existing OCR engine/workflows and one proposed report column; no speculative infrastructure, new approval system, or calendar-driven rescanning. |
| Standards, Spec, and risk | Pass for this specification: sequential outcomes recorded above; implementation results are not claimed. |
| Changed TypeScript quality | N/A: no TS/TSX changes. |
| Karpathy guidelines | Pass: explicit facts/assumptions, one-file scope, and testable criteria; no speculative infrastructure. |
| Code splitting | N/A: no bundle changes. |
| Gauge improvements | Not measured; no empirical improvement claimed. |
| Security review | Pass at design level using security-review and Laravel security/validation guidance; implementation security tests remain not run. |
| Verification before completion | Document hygiene, referenced paths, and revision scope are checked for delivery; no application test/build claims. |

Reuse audit: existing helpers, lifecycle services, decision service, viewers, and tests are identified above. Dead-code scan: N/A for this documentation-only revision; no application code is removed. Vault learning: findings are recorded in this specification; no unrelated learning-log edits are needed.

The implementation review stack remains required when code changes: simplify, sequential Standards/Spec/risk review, changed TypeScript quality, Karpathy guidelines, relevant bundle behavior, measured-claim review, Laravel/upload security, and fresh verification before completion. Reuse and dead-code checks apply to the implementation. This document does not claim those future gates have passed.

## Approval checkpoint

This revised specification is ready for user approval and, after approval, a separate implementation plan. No additional architectural decision is needed. The remaining technical assumptions are verification work for that later plan: compatible localized evidence and cancellation from the reused reader, exact local asset packaging/paths, and availability of permitted representative fixtures. If a detail cannot be verified, retain uncertainty/nonblocking fallback and record the limitation rather than changing the approved architecture or claiming deployment readiness.

The existing registration, email/location validation, private uploads, signed resubmission/reapplication limits, immutable history/replays, supporting documents, customer OCR, and Super Admin decision workflow remain authoritative. The future plan will cover only the necessary reusable OCR subset, localized recognition/matching, bounded persistence/version binding, integration into existing forms/admin review, local asset deployment, focused tests/regressions, and verification.

Stop here for the user's approval of the revised specification. Do not write the implementation plan, install dependencies, create migrations/assets, edit controllers/components, or change behavior during this task.
