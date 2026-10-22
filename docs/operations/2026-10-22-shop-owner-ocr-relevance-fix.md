# Shop-owner OCR relevance correction — October 22, 2026

> Latest correction: **all five supplied originals now receive their correct family and remain eligible in the actual form; both wrong-slot controls require replacement.** The 20-case synthetic browser matrix also passes. The earlier original-image table used three incorrect filename labels and is superseded by the corrected baseline and final results below. This is a scoped development fix, not deployment readiness or measured OCR accuracy.

Scope: the user's current OCR relevance, applicant feedback, upload preview and filename request. Work stays in `.worktrees/shop-owner-registration-ocr`. Existing uncommitted changes were snapshotted under `output/ocr-relevance-fix/baseline/` before editing. No deployment or production migration is authorized.

## Reproduction before implementation

The real Tesseract.js browser engine processed a repository-generated synthetic portrait through the normal registration form. Normal OTP verification used a loopback SMTP capture; no authentication middleware was bypassed. The portrait produced six OCR characters, two lines and confidence 0.33. The pixel sampler called it `document_like`; relevance fell through to `uncertain_relevance`, a 50% matching score, an enabled Submit Registration button and an allowed submission confirmation. Confirmation was canceled before a backend registration write. Evidence: `output/ocr-relevance-fix/baseline-engine.json`, `baseline-scans.json`, `baseline-form.json` and `baseline-form.png`. These contain synthetic-only safe summaries, not applicant OCR values or OTPs.

Root cause: `classifyShopOwnerDocumentRelevance` required explicit blank/non-document pixel heuristics or multiple unrelated high-confidence text lines to block. Absence of meaningful document evidence otherwise returned uncertainty. Successful execution was incorrectly sufficient for the practical relevance gate. The existing tests explicitly allowed an unreadable `document_like` image.

Other demonstrated findings:

- The hook reconstructs states for failed scans with `uncertain_relevance`, losing `technical_failure` and displaying the wrong explanation.
- The real Mayor's Permit and BIR fixtures were recognized as their correct families. Their wrapped address was read only from the first labelled line, then treated as a complete conflicting address. Type recognition and field uncertainty need different applicant messages; there is no evidence to indiscriminately relax family-confidence rules.
- `DropZone` renders its non-compact filename without width/truncation constraints. That filename comes from `File.name`, independently from the preview object URL; no OCR text is assigned to it. A raw data/blob URL passed as a filename is also displayed. New regression cases reproduce these rendering problems.
- Upload-success modal HTML interpolates filenames without the existing `escapeHtml` helper. A synthetic markup-looking filename reproduces unescaped markup in the modal.
- The four required uploads use distinct keyed file/preview state and slot mappings. Browser preview isolation is still to be verified; no shared-state corruption is assumed.
- The submit button and entry guard already use `submissionBlockReason`; the async confirmation/CSRF path also needs a final check before dispatch.

Baseline focused frontend suites: 54 tests passed. New regression tests then failed on missing relevance blocking, the lost technical-failure label, filename containment/URL display, requested full-document preview/selection label and unsafe filename HTML. These are functional failures, not compilation/setup failures. Cached pnpm 11.14.0 is invoked through `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...`; no project dependency was added.

## File-level execution plan

1. Correct the relevance fallback in `shopOwnerDocumentRecognition.ts`; keep three independent family indicators and existing administrative scoring. Treat partial matching addresses conservatively. Verify recognizer/hook regressions.
2. Preserve technical-failure state in `useShopOwnerOcr.ts`; retain generation invalidation, sequential workers and signed retained-file handling.
3. Clarify `ShopOwnerOcrStatus.tsx`, constrain/sanitize filename display and show full original previews through existing `DropZone` options. Escape upload-modal filenames and recheck the submission guard immediately before dispatch in `ShopOwnerRegistration.tsx`. Preserve `registrationDocumentPayload.ts` original-file/metadata contract.
4. Run real-engine/form cases for synthetic irrelevant images, all supported document families, wrong slots, uncertainty/conflicts, pending/failed scans, replacement and independent previews. Record button and actual handler outcomes. Then run affected frontend/backend regressions, local asset checks, production build and scoped diff hygiene; record remaining limits and the sequential review.

## Test-environment isolation

Final browser checks use a dedicated PHP listener on port 8017 and worktree Vite on 5187. The test runner checks an isolation response header before requesting OTP or submitting. The normal HTTP kernel uses an explicit disposable SQLite copy and loopback SMTP capture; no application auth route or middleware is bypassed. An initial attempt collided with an existing server on port 8002; that listener was left intact, and results from it are not accepted as OCR verification. Synthetic OTP requests made during that failed setup are not evidence of delivery or application behavior. No test OTP or email body is recorded.

The real-browser runner freezes Vite HMR in a temporary output-local configuration after observing an unsolicited development full-reload during an earlier attempt. It does not change application bundling/configuration. Pending/replacement checks delay actual local worker delivery for deterministic timing; other recognition cases use the normal worker. Earlier harness attempts exposed alert-transition races and an incorrect assumption about clearing an uncontrolled native file input. Those attempts are not counted as completed matrix passes. Removal is checked against current application evidence, preview and the normal required-file validator.

## Implemented correction

- `shopOwnerDocumentRecognition.ts`: require positive document evidence after successful recognition; retain reliable three-group family recognition, plausible unsupported-layout uncertainty, field matching and categorical scoring. A partial matching address line is uncertain rather than a fabricated complete conflict.
- `registrationOcr.ts`: remove the owner pixel-texture/layout sampler. A stripe/grid/photo texture cannot supply missing issuer/document evidence. Keep owner bounds/deadline/cleanup and customer default reading behavior.
- `useShopOwnerOcr.ts`: preserve genuine technical-failure relevance when deriving current states. Existing generations, queue and removal semantics remain authoritative.
- `ShopOwnerOcrStatus.tsx`: distinguish recognized type with uncertain/conflicting details from uncertain recognition and technical failure. Replacement requests use the user-approved concise message.
- `ShopOwnerRegistration.tsx`: repeat the relevance guard after awaited confirmation/CSRF retrieval and before transport. Existing button and initial-handler guards remain. Reuse original upload state with unique accessible input identifiers, contained full-document previews and selection-only feedback. Escape modal filenames.
- `DropZone.tsx` and `utils/uploadFileName.ts`: bound filename layout, suppress URL-like filename labels/control characters, preserve original preview bytes, and keep existing defaults for other callers.
- Focused recognizer/hook/form/drop-zone regressions and synthetic passport/UMID fixtures cover the correction. `ShopOwnerRegistration.age.test.tsx` now waits for its existing Leaflet dependency before its rapid wizard teardown, resolving an observed asynchronous jsdom teardown error without changing age assertions or production maps.
- `registrationDocumentPayload.ts` was audited and did not need modification. Original files/field names, optional report serialization, selected type/date metadata, submission keys and signed endpoints remain in the existing flow. No Laravel production source, dependency, environment file, database schema, decision service or customer admission policy changed in this correction.

## Automated verification

Commands use cached pnpm through `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...` because pnpm is absent from the shell PATH. No dependency installation/update was required. Package-store traversal required the authorized local sandbox escalation.

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/shopOwnerScreening.test.ts resources/js/Pages/UserSide/Auth/__tests__/shopOwnerDocumentRecognition.test.ts resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOptionalDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistration.age.test.tsx resources/js/Pages/UserSide/Auth/__tests__/RegistrationDocumentMetadataFields.test.tsx resources/js/Pages/UserSide/Auth/registrationOcrOwner.test.ts resources/js/Pages/UserSide/Auth/registrationOcr.test.ts resources/js/Pages/UserSide/Auth/registrationNameMatch.test.ts resources/js/Pages/UserSide/Auth/registrationDocumentScreening.test.ts resources/js/components/form/form-elements/__tests__/DropZone.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationOcr.test.tsx
php artisan test tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php tests/Feature/ShopDocuments/ShopOwnerOcrPersistenceTest.php tests/Feature/ShopDocuments/ShopOwnerOcrBootstrapTest.php tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php tests/Feature/SuperAdmin/ShopOwnerOcrReviewTest.php tests/Feature/SuperAdmin/ShopOwnerOcrDecisionIsolationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php --stop-on-failure
```

Results: frontend **15 files / 203 tests passed**, exit 0; backend **113 tests / 815 assertions passed**, exit 0. Earlier new regressions failed on the intended functional differences before their fixes. The first broad frontend attempt had 203 passing assertions but two unhandled age-suite Leaflet teardown errors and exit 1; the synchronization correction above produced a clean exit 0 on rerun. Existing warnings include Node's localStorage option, a mocked jsdom canvas fallback, missing local CARTO configuration and PHPUnit doc-comment metadata deprecations. No TypeScript compiler/lint pass is claimed; those gates are not configured here.

After changing the remaining attachment badges from Ready to Attached and preserving a visible inset keyboard-focus ring on the clipped upload container, the final narrow command passed **3 files / 23 tests**, exit 0:

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/components/form/form-elements/__tests__/DropZone.test.tsx
node --test scripts/prepare-owner-ocr-assets.test.mjs
node scripts/prepare-owner-ocr-assets.mjs --check
pnpm run build --outDir output/ocr-relevance-fix/production-build
php -l tests/Browser/shop_owner_ocr_server.php
```

Packaging tests: **4 passed**. Asset integrity check passed before and after build for `tesseract-7.0.0-core-7.0.0-eng-1.0.0`. Build: **3,851 modules, 43.62 seconds, exit 0**, with the existing unresolved-at-build `/images/auth-geometric-pattern.svg` warning. Output stayed under `output/ocr-relevance-fix/production-build`, preserving tracked `public/build`. Manifest traversal confirms the owner page's 40 static chunks do not include `registrationOcr.ts`; that reader remains a dynamic entry/import.

The ignored `public/ocr/` was packaged locally into `output/ocr-relevance-fix/owner-ocr-assets.zip`: **24 files / 20,146,057 bytes**, archive integrity and every original/archived SHA-256 agree (`package-check.json`). This proves local archive inclusion, not Hostinger delivery. It is a runtime-assets test archive, not a complete application release. A separately approved release still needs matching frontend/PHP, the additive migration if not previously deployed, correct public paths, CSP and target-host checks.

## Actual browser verification

`python tests/Browser/shop_owner_ocr_relevance.py --mode verify`: **20 case assertions passed, exit 0**, Chromium **151.0.7922.34**. Normal OTP verification, GPS/address policy, wizard metadata, terms and confirmation run against disposable local SQLite/SMTP. OCR uses the actual locally hosted Tesseract 7 worker/core/English model in a new browser context. No recognition result or form validation is mocked.

The final Inertia transport alone is intercepted after the actual handler/confirmation/CSRF path, so allowed cases do not create registrations or send reviewer notifications. For disabled cases, the real React submit callback is also invoked directly to verify the independent handler guard; it stops without transport. Every allowed transport preserves all four original selected-file hashes. This verifies the client admission gate, not a completed backend browser registration or final approval.

Per-case safe evidence is in `output/ocr-relevance-fix/browser-results.json` and `browser-matrix.csv`. The table records actual engine text length/confidence, not raw text or applicant data. Confidence is the informational OCR summary, not an accuracy probability.

| Case / slot | OCR chars / confidence | Family | Relevance | Score | Submit | Handler |
| --- | --- | --- | --- | --- | --- | --- |
| pending-required-scans / valid_id | pending | unresolved | pending | none | disabled | stopped |
| correct-dti-permit-bir-national-id / business_registration | 323 / 0.95 | dti_registration | plausible_document | 100 | enabled | transport_dispatched |
| correct-dti-permit-bir-national-id / mayors_permit | 311 / 0.95 | mayors_permit | plausible_document | 50 | enabled | transport_dispatched |
| correct-dti-permit-bir-national-id / bir_certificate | 375 / 0.95 | bir_certificate | plausible_document | 50 | enabled | transport_dispatched |
| correct-dti-permit-bir-national-id / valid_id | 254 / 0.95 | national_id | plausible_document | 100 | enabled | transport_dispatched |
| selfie / business_registration | 6 / 0.33 | unresolved | clearly_irrelevant | 50 | disabled | stopped |
| cartoon-meme / business_registration | 19 / 0.32 | unresolved | clearly_irrelevant | 50 | disabled | stopped |
| blank / business_registration | 0 / 0 | unresolved | clearly_irrelevant | none | disabled | stopped |
| random-photograph / business_registration | 0 / 0 | unresolved | clearly_irrelevant | none | disabled | stopped |
| unrelated-text / business_registration | 99 / 0.95 | unresolved | clearly_irrelevant | 50 | disabled | stopped |
| national-id-in-dti-slot / business_registration | 254 / 0.95 | national_id | incompatible_document_type | 0 | disabled | stopped |
| same-dti-file-in-dti-and-permit-slots / mayors_permit | 323 / 0.95 | dti_registration | incompatible_document_type | 0 | disabled | stopped |
| alternate-lgu-permit-layout / mayors_permit | 332 / 0.95 | mayors_permit | plausible_document | 50 | enabled | transport_dispatched |
| correct-sec / business_registration | 321 / 0.95 | sec_registration | plausible_document | 100 | enabled | transport_dispatched |
| dti-conflict / business_registration | 322 / 0.95 | dti_registration | plausible_document | 0 | enabled | transport_dispatched |
| dti-uncertain / business_registration | 293 / 0.95 | dti_registration | plausible_document | 50 | enabled | transport_dispatched |
| dti-poor-photo / business_registration | 323 / 0.95 | dti_registration | plausible_document | 100 | enabled | transport_dispatched |
| drivers-license / valid_id | 241 / 0.95 | drivers_license | plausible_document | 0 | enabled | transport_dispatched |
| passport / valid_id | 245 / 0.95 | passport | plausible_document | 0 | enabled | transport_dispatched |
| umid / valid_id | 246 / 0.95 | umid | plausible_document | 50 | enabled | transport_dispatched |
| unsupported-government-id / valid_id | 199 / 0.92 | unresolved | uncertain_relevance | 50 | enabled | transport_dispatched |
| replacement-during-active-scan / business_registration | 6 / 0.33 | unresolved | clearly_irrelevant | 50 | disabled | stopped |
| blocked-worker-asset / business_registration | technical_failure | unresolved | technical_failure | none | enabled | transport_dispatched |

Additional observed checks: replacement during an active worker discards the old family/result; removal clears evidence and preview and normal required-file validation prevents reaching Submit; independent preview hashes match each original upload with `object-fit: contain`; the 244-character filename ellipsizes within the 1440/390-pixel layouts (408/232-pixel text boxes); a URL-like filename displays Selected document. Screenshots `selfie-form.png`, `blocked-worker-asset-form.png`, `filename-1440.png` and `filename-390.png` contain synthetic data only. The selfie and mobile filename screenshots were visually inspected.

All intended synthetic irrelevant/wrong-slot examples were blocked. Supported DTI/SEC/Mayor/BIR/National ID/driver/passport/UMID families were recognized; the supported documents remained eligible even with field uncertainty/conflicts. The unsupported government-like ID remained uncertain and eligible. The intentionally blurred DTI remained readable and scored 100%; it does not demonstrate tolerance of every poor photograph. Wrapped matching addresses remain uncertain instead of false conflicts; date-bearing driver/passport fixtures conflict with the intentionally retained non-expiring ID metadata and stay submittable at 0%.

Actual resource responses: same-origin `worker.min.js` and `core/tesseract-core-relaxedsimd-lstm.wasm.js` returned 200/application-javascript; `lang/eng.traineddata.gz` returned 200/application-gzip. The compressed model was successfully used without an HTTP Content-Encoding header. No external OCR requests, runtime errors or HMR reloads occurred. Blocking the worker produced a distinct technical_failure with no score and allowed transport. Only this Chromium core-capability path was exercised in this correction.

## Acceptance coverage and sequential review

| Approved group | Evidence for this correction | Limit |
| --- | --- | --- |
| A1 Recognition | Real engine/form family checks for DTI, SEC, two LGU layouts, Form 2303 and four supported ID families; unsupported ID stays uncertain. | Synthetic layouts only. |
| A2 Roles/matching | Recognizer regressions and actual allowed 0% conflict; partial matching address stays uncertain. | Real legal/trade-name layouts not sampled. |
| A3 Recognition errors | Successful empty/junk/drawing/text reads block; local-confidence/ambiguity cases pass. | No measured camera-photo error rate. |
| A4 Dates | Affected unit/backend regressions; actual dated/non-expiring/conflicting metadata cases. | Historical validity remains governed by unchanged server rules. |
| A5 Scoring | Shared contract and admin regression suites; actual 100%, 50%, 0% and null cases. | No authenticity probability is inferred. |
| A6 Admission | Actual button AND handler block pending/irrelevant/wrong-family cases, allow field conflicts and worker failure; original validation preserved. | Browser final transport is captured, not committed to the DB. |
| A7 Lifecycle | Actual active replacement/removal/pending checks plus hook deadline/cleanup/unmount regressions. | Device memory/performance not measured. |
| A8 Deployment | Build, deferred import graph, local cold-context assets, missing-worker fallback, integrity/archive checks. | Hostinger, CSP, subpath and other core paths not retested here. |
| A9 Persistence | Fresh nullable/private report, validation, replay/rollback regression suites pass; no production PHP change. | No production migration run. |
| A10 Version isolation | Signed retained/replaced-file and immutable-history backend tests plus resubmission form regression. | Authenticated signed-resubmission browser journey not run. |
| A11 Authority/privacy | Fresh private-access and per-score decision-isolation/workflow tests pass; filename HTML escaped; no raw values in reports. | Authenticated Super Admin browser journey not run. |
| A12 Regressions | Affected 203 frontend/113 Laravel tests, customer helper defaults and unchanged original-file hashes; final UI 23-test rerun. | Full project frontend/Composer suites not rerun for this correction. |

Review ran sequentially in the main agent, without delegated reviewers:

1. **Simplify — pass:** remove the pixel sampler rather than add a face/model service; reuse the existing family recognizer, scoring, queue, upload payload and DropZone. No new dependency/framework.
2. **Standards, Spec, risks — pass within recorded scope:** inspect the delta against before-edit copies, preserve existing dirty changes, distinguish replacement from score and technical failure, and retain server validation/manual authority.
3. **Changed TS/React quality — pass by inspection:** typed relevance states and small pure filename helper; no new `any`, unsafe request model or general parser/queue abstraction. React generation/ref cleanup remains in the existing hook. This is not a compiler/lint claim.
4. **Karpathy guidelines — pass:** reproduce first, watch focused tests fail meaningfully, make a surgical correction and state the synthetic/browser/transport limits explicitly.
5. **Code splitting — pass:** type-only reader references, existing dynamic import, production manifest graph and actual local asset requests; no eager owner OCR dependency or new manual chunk.
6. **Gauge improvements — observed functional change:** the same synthetic portrait changed from allowed uncertainty to replacement-required with disabled Submit/stopped handler. No accuracy, fraud-rate, latency or memory improvement measured.
7. **Security/Laravel boundary — pass within tested scope:** escaping prevents filename HTML injection; URL-like labels do not expose data URLs; no raw OCR/checksum/path enters ordinary props; local helper uses normal middleware with disposable DB/SMTP, and production authorization/persistence/decision code is unchanged and regression-tested. Browser tampering remains an accepted limitation.
8. **Verification before completion — pass for this scoped correction:** fresh tests, real-engine form matrix, production build, asset/archive integrity and diff hygiene support only the claims recorded here. Missing release checks remain open.

Reuse audit: existing personal-name matching, family evidence, Laravel score contract, queue/generations, registration multipart handling, private viewer and decision authority are reused. Dead-code review found and removed the now-unused pixel sampler; legacy pixel flags remain only in negative regression/baseline observation cases to prove they cannot qualify an image. No unrelated code was removed. Task-specific lessons remain in this report; no unrelated learning-log content was edited.

## Remaining risks and handoff

- All new image cases are generated synthetic documents/drawings. No real camera selfie, real landscape photograph, official government layout or production applicant document was used; no production false-block/missed-detection rate is known. The observed results must not be generalized into a fraud/accuracy claim. A real but unreadable document without positive cues will require retaking it.
- A determined applicant can bypass browser code or deliberately cause a technical failure. The requested nonblocking technical fallback remains available; this is practical client guidance, not server authentication. Text resembling document cues can also fool OCR. Manual inspection remains necessary at every score.
- Browser worker-crash/unsupported-browser/timeout variants are automated reader/hook regressions; the actual form failure case here is a blocked worker resource. Other browser engines/core variants and low-memory phones were not exercised in this correction.
- No real external mailbox delivery was tested. Normal OTP send/verify succeeded through the dedicated loopback capture; `.env` and the user's mail configuration were not changed.
- No application was approved/rejected or written by the browser matrix. Signed resubmission and admin browser journeys, full project suites, production CSP/base paths and Hostinger release delivery remain open as specified above. This report does not mark the original Phase 7 or deployment readiness complete.
- No deploy, push, merge, production migration, dependency update or destructive database operation occurred. Build output and the runtime test archive are local artifacts only. Existing user changes remain unstaged.

Final hygiene: `git diff --check` passed, and `git diff --no-index --check -- /dev/null <path>` found no whitespace issues in all seven new source/document files after normalizing them to the repository's LF convention. Protected-area diff assertion confirms `.env`, package/lockfiles, tracked `public/build`, production `app/` and `database/` remain unchanged. No staged files were added.

The dedicated SQLite/SMTP test HTTP server was stopped after verification. Worktree Vite was restored with normal HMR on 5187 (output-local config/cache; application Vite config unchanged). The existing manual URL `http://127.0.0.1:8000/shop-owner-register` returned 200 and referenced this worktree frontend; the Vite client also responded successfully. Hard-refresh an already open registration tab before manually checking the correction. This availability check does not test the user's external mail delivery or change its configuration.

## Follow-up review and real-document verification readiness - October 22, 2026

The user's supplied **Full Verification Review** approves the scoped relevance correction and keeps the branch in development pending the remaining release checks. That approval does not turn the prior synthetic results into real-document evidence. The 203 frontend tests, 113 Laravel tests, 20 browser cases and build results above remain the earlier recorded runs; they were not rerun for this documentation-only readiness review. No further recognition/scoring change, engine, service or architecture is requested or introduced here.

Five inline samples were visually inspected. Visual interpretation is not a Tesseract execution result, an authenticity check or permission to infer masked values:

| Inline sample | Visible coverage | Expected test handling, not an executed result |
| --- | --- | --- |
| DTI certificate | Business-name-registration heading, issuer text and certificate structure; identity fields are masked. | Check family relevance separately from masked identity fields. Missing/redacted names cannot justify a confident match or conflict. |
| SEC certificate | Commission issuer, incorporation heading and corporate registration structure. | Check the selected SEC slot and legal/trade-name role conservatively; do not require the representative to equal the corporation. |
| Cainta business permit | LGU/permit heading, labelled business/address and printed 2025 expiry. | Layout-variation sample; a reliably read expired date may produce a 0% date conflict while a plausible document remains submittable. |
| Quezon City permit to operate | Different LGU/table layout, permit reference and printed 2020 expiry. | Second layout-variation sample with the same separation of relevance and date findings. |
| BIR Form 2303 | Form number, registration heading and taxpayer/business/address structure; key identity fields are masked. | Check Form 2303 recognition without inventing annual expiry or masked values; uncertainty remains eligible. |

The two visible permits identify **Cainta, Rizal** and **Quezon City**, not Cavite. They can expand layout/field-conflict coverage but cannot satisfy the specifically requested two different Cavite-LGU specimens. Their printed expiry dates precede the October 22, 2026 reference day; no final OCR score is assigned here because no engine scan/form comparison was executed.

The latest attachment directory contains only `Pasted text.txt`; no JPG/PNG originals are accessible there for browser file upload. Repository fixture inventory still consists of the previously documented synthetic images and screenshots. The separately named manual DTI PNG was inspected and is also explicitly watermarked synthetic. The inline images were not recreated, substituted with synthetic fixtures, or counted as executed OCR tests. A text request for the authorized originals' local folder path is pending.

| Next verification gate | Current dependency/status |
| --- | --- |
| Actual supplied DTI/SEC/BIR/permit engine reads | Needs accessible original JPG/PNG file paths. Record family, relevance, score/reasons, button state and handler outcome without persisting raw OCR values. |
| Two Cavite LGUs and supported ID specimens | Additional permitted/redacted files needed; not present among the inline samples. |
| Ordinary camera selfie, meme and random photograph | Original permitted files needed; prior procedural drawings do not count as camera photographs. |
| Complete registration written to Laravel | Use the existing isolated SQLite/SMTP environment and normal OTP/location flow, remove only the test transport capture, then verify pending status, original private files and immutable report/version records. Not run in this review. |
| Signed rejected resubmission | Obtain a genuine signed URL through the normal local workflow; verify retained/replaced files and predecessor isolation without changing admission rules. Not run in this review. |
| Authenticated Super Admin journey | Use a disposable local reviewer through normal authentication/MFA, inspect private originals, supply required reviewer metadata and exercise explicit decisions on separate test applications. No bypass route/session injection. Not run in this review. |
| Full project regressions | Run separately after targeted defects are resolved, preserve unrelated failures and report actual exit codes. Not rerun here. |
| Hostinger/CSP/base-path and mobile measurements | Remain later release/device checks; no host deployment or production access is authorized by this review. |

False blocks of plausible genuine documents are the primary focus of the next round. Preserve the existing technical fallback, 100%/50%/0% rules and manual authority. If a case fails, retain its safe evidence/provenance, reproduce it and add the narrow failing regression before changing the existing reader/recognizer. This readiness review does not assert new test passes or production readiness.

## Historical supplied-image round - superseded because three specimen labels were wrong

**Do not use this section's table or DTI/BIR/Cainta-specific diagnosis as a valid baseline.** The subsequent correction round visually rechecked each original and found three filename-to-document labels were wrong. SEC and Quezon City were labelled correctly. Original evidence is retained for traceability; the corrected inventory and rerun below are authoritative. In particular, the supposed weak DTI read was the BIR image placed in the DTI slot.

The user supplied `C:\Users\slyha\Downloads\asd`. All five JPG originals were visually inventoried and read from that folder without copying or modifying them. They are user-authorized real-format samples, not independently authenticated government issuances or newly captured phone photographs. They contain the DTI/SEC/BIR and Cainta/Quezon City samples discussed above. No ID, Cavite-LGU specimen, selfie, meme or random photograph is present.

**Outcome: the real-format recognition/relevance acceptance gate failed.** Two correctly slotted sample certificates were blocked. No application code, recognition threshold or scoring rule was changed during this verification round.

The output-local runner reuses `tests/Browser/shop_owner_ocr_relevance.py` for normal OTP/GPS/validation, actual local Tesseract reading, the real Submit button/handler and transport capture. Other document slots are restored to the synthetic baseline between independent cases. Names, addresses and submitted dates remain synthetic test context; this round is not a claim that specimen identities match that invented applicant.

```powershell
python output/ocr-real-documents/verify_specimens.py --folder 'C:\Users\slyha\Downloads\asd' --inventory-only
python output/ocr-real-documents/verify_specimens.py --folder 'C:\Users\slyha\Downloads\asd'
```

Inventory: five files, no image copies. Final isolated browser run: exit 0 for the harness consistency checks, seven observations, Chromium 151.0.7922.34, zero browser runtime errors, zero external OCR requests, and unchanged SHA-256 values for every original. **Exit 0 does not mean the document-recognition acceptance criteria passed.** The final Inertia transport remained intercepted before application writes; normal OTP used loopback SMTP, not an external inbox.

| Supplied sample / selected slot | OCR characters / summary confidence | Recognized family | Relevance | Score | Submit button | Actual handler |
| --- | --- | --- | --- | --- | --- | --- |
| DTI / DTI | 1,633 / 0.54 | Unresolved | Clearly irrelevant — false block against supplied specimen label | 50% | Disabled | Stopped |
| SEC / SEC | 2,130 / 0.73 | Unresolved | Clearly irrelevant — false block against supplied specimen label | 50% | Disabled | Stopped |
| Cainta / Mayor's Permit | 866 / 0.89 | Unresolved | Uncertain relevance | 50% | Enabled | Transport reached |
| Quezon City / Mayor's Permit | 2,208 / 0.65 | Unresolved | Uncertain relevance | 0% | Enabled | Transport reached |
| BIR / BIR | 1,820 / 0.86 | Unresolved | Uncertain relevance | 0% | Enabled | Transport reached |
| SEC / DTI, wrong-slot control | 2,130 / 0.73 | Unresolved | Clearly irrelevant | 50% | Disabled | Stopped |
| DTI / Mayor's Permit, wrong-slot control | 1,633 / 0.54 | Unresolved | Clearly irrelevant | 50% | Disabled | Stopped |

The two wrong-slot controls were blocked because of insufficient relevance, **not** because the correct incompatible family was reliably recognized. They do not prove successful real-format wrong-family detection. Likewise, the three enabled cases show conservative submission eligibility, not successful family classification. No sample obtained a confident family label. The Quezon City result includes `business_identity_conflict`; BIR includes `date_conflict`. Those findings were recorded, not adjudicated as correct field extraction or proof of forgery. Redactions, role association and synthetic comparison metadata still need careful field-level follow-up.

### Reproduced causes and next correction boundary

- **SEC localized-confidence defect:** keyword-only diagnostics show the issuer words at 0.95 and heading words at 0.96, while unrelated words on those same OCR lines reduce the whole-line minima to 0.03 and 0.30. `signalForPass` and `hasPlausibleGovernmentDocumentStructure` use `confidenceOfWords(line.words)` over the entire line. This discards usable issuer/heading evidence and violates the intended distinction between confidence of a used signal and unrelated page text. Fixing it should retain confidence for every material matched word and independent signals, without lowering thresholds or allowing a single keyword.
- **DTI weak reading/layout limitation:** the full-image read produced confidence 0.54; the relevant heading's minimum was 0.39 and the exact issuer phrase was not recovered by the diagnostic. This is a successful but weak read, not worker failure. The existing evidence cannot justify declaring a reliable DTI family. A line-confidence correction alone is not established as sufficient for this specimen. Any image-preparation/layout change needs a controlled real-engine comparison within the existing 1,800-pixel bound, deadline and original-upload contract before implementation.
- **Cross-case contamination was removed:** the initial exploratory run left the blocked SEC file in another slot, which made later otherwise eligible samples appear blocked. That artifact is retained separately as `initial-results-with-carryover.json` and is not used for the table. The final round restores other slots and records `blocking_slots`, demonstrating that each correctly slotted DTI/SEC image independently causes its own block.

Evidence: `output/ocr-real-documents/source-inventory.json`, `results.json`, `matrix.csv`, `browser.log` and the output-local runner. Reports contain fixed check outcomes, counts/confidences and file identity, not raw OCR, extracted names/addresses/numbers or images. The first attempt timed out during development page startup before OCR; the page-startup wait was increased to 90 seconds, leaving the engine's 60-second deadline unchanged. It is not counted as an OCR execution result.

The earlier 203/113-test and production-build results were not rerun or promoted by this read-only application verification. The branch remains in development with these additional release-blocking findings. Real camera/ID/two-Cavite-LGU coverage, persisted browser submission, signed resubmission, authenticated Super Admin, full suites and Hostinger/CSP checks remain open. No deployment, merge, push, production migration, `.env` edit, application change or dependency installation occurred.

## Corrected inventory, implementation and real-engine rerun - October 22, 2026

This section supersedes the mislabelled original-image round above. Each opaque filename was visually rechecked before the new baseline. The correct prefixes are **DTI `0dUd` (46,912 bytes), SEC `SkQ` (371,910), Cainta `bPWR` (436,335), Quezon City `IEw` (321,055), BIR `-fkq` (118,732)**. The earlier manifest had assigned BIR to DTI, DTI to Cainta, and Cainta to BIR. SEC and Quezon City were correct. This was a test-manifest error, not image substitution by the application. The old results/inventory are retained as `output/ocr-real-correction/previous-*-invalid-labels.json`; they cannot support their former document-specific conclusions.

Before further edits, the affected dirty source/tests/report were copied with SHA-256 records into `output/ocr-real-correction/before/` and `before-hashes.json`. Only this worktree was edited. Original JPGs remain in the authorized Downloads folder; they were neither copied into the repository nor modified. No threshold, score contract, applicant field, database, mail configuration or decision policy changed.

### Correctly labelled baseline and confirmed causes

The corrected baseline ran the same seven form scenarios with the saved pre-correction recognizer and reader. It restores other slots between samples. Safe evidence is in `corrected-baseline-results.json` and its matching inventory/log.

| Original / selected slot | Before characters / summary confidence | Before family / relevance | Before score | Button / handler |
| --- | --- | --- | --- | --- |
| DTI / DTI | 866 / 0.89 | Unresolved / uncertain | 50% | Enabled / transport reached |
| SEC / SEC | 2,130 / 0.73 | Unresolved / replacement required | 50% | Disabled / stopped |
| Cainta / Mayor's Permit | 1,820 / 0.86 | Unresolved / uncertain | 0% | Enabled / transport reached |
| Quezon City / Mayor's Permit | 2,208 / 0.65 | Unresolved / uncertain | 0% | Enabled / transport reached |
| BIR / BIR | 1,633 / 0.54 | Unresolved / replacement required | 50% | Disabled / stopped |
| SEC / DTI | 2,130 / 0.73 | Unresolved / replacement required | 50% | Disabled / stopped; incompatible family not established |
| DTI / Mayor's Permit | 866 / 0.89 | Unresolved / uncertain | 50% | Enabled / transport reached; wrong family missed |

Confirmed defects and the smallest corrections:

- **Confidence covered unrelated words.** Recognition now measures the minimum confidence of the words actually matched by a cue, without averaging. Independent issuer, heading and structure still require distinct supporting lines within one pass. Up to three adjacent, geometrically associated lines support wrapped phrases; distant regions and different passes are never joined. The same helper qualifies plausible-document evidence, preventing unrelated line noise from causing a false replacement request.
- **Shared labels invented a competing family.** A city and a business-name label incorrectly competed with a reliable DTI certificate even without a permit heading. A competing partial family now also needs its category heading. Two genuinely supported conflicting families still remain uncertain.
- **SEC structure and BIR heading were too narrow.** SEC can use separate incorporation-body structure when its registration-reference label is weak. The printed `2303` heading is supported with an independent BIR issuer and taxpayer structure. Neither a lone number nor a single government keyword qualifies a family. Existing permit rules, with localized confidence and page segmentation, recognize both supplied LGU layouts; no city-specific template was added.
- **Full-page preparation was unsuitable for the small BIR original.** Controlled real-engine comparisons justified bounded certificate upscaling and `PSM.AUTO`, using one recognition call. No contrast pass or retry was added. A synthetic UMID regression exposed that applying this to IDs reduced heading confidence to 0.59. The final hook explicitly selects certificate or ID layout: business documents use the new preparation, while owner IDs preserve native sizing and the previous worker segmentation default. The customer reader remains unchanged.

The actual DTI original is 675x778 and was already readable, unlike the earlier incorrectly labelled 0.54 reading. Its heading spans lines; field identities are redacted. The BIR original is 603x804. Background/table segmentation affects these samples; color, logo or a fixed layout is not an acceptance rule.

Controlled comparison results (`dti-preparation.json`, `bir-preparation.json`; actual local engine, original inputs unchanged):

| Single-read variant | DTI characters / summary confidence | BIR characters / summary confidence |
| --- | --- | --- |
| Native, existing block segmentation | 866 / 0.89 | 1,633 / 0.54 |
| Bounded upscale, block segmentation | 891 / 0.92 | 1,747 / 0.68 |
| Bounded upscale plus contrast, block segmentation | 875 / 0.92 | 1,503 / 0.75 |
| Bounded upscale, sparse segmentation | 910 / 0.93 | 1,727 / 0.72 |
| Bounded upscale, automatic segmentation | 893 / 0.92 | 1,278 / 0.88 |

The working long edge stays at most 1,800 pixels and enlargement at most 3x; large images still downscale. Final production preparation encodes the working canvas as before, so its exact character counts differ from the direct-canvas AUTO experiment. Only cue recovery and functional outcomes are compared; summary confidence is not measured accuracy. The 60-second total deadline, operation bounds, one-worker queue, late-result invalidation and cleanup remain unchanged.

### Final supplied-original form results

```powershell
python output/ocr-real-documents/verify_specimens.py --folder 'C:\Users\slyha\Downloads\asd'
python output/ocr-real-correction/diagnose.py
python tests/Browser/shop_owner_ocr_relevance.py --mode verify --output output/ocr-real-correction/negative-browser-final
python output/ocr-real-correction/check_evidence.py
```

All commands exited 0. The last command independently asserts the expected families, relevance/button/handler outcomes, unchanged original hashes, negative controls and fallback from the saved results; it does not count a harness-consistency exit as recognition success. The diagnostic module uses a fresh output-only URL to avoid reusing Vite's earlier cached diagnostic definition. It records cue counts, line indexes and confidence only.

| Original / selected slot | Final characters / summary confidence | Final family | Relevance / score | Button / actual handler |
| --- | --- | --- | --- | --- |
| DTI / DTI | 881 / 0.93 | DTI | Plausible / 50% | Enabled / transport reached |
| SEC / SEC | 1,696 / 0.93 | SEC | Plausible / 50% | Enabled / transport reached |
| Cainta / Mayor's Permit | 1,814 / 0.91 | Mayor's Permit | Plausible / 0% | Enabled / transport reached |
| Quezon City / Mayor's Permit | 1,972 / 0.90 | Mayor's Permit | Plausible / 0% | Enabled / transport reached |
| BIR / BIR | 1,443 / 0.86 | BIR Form 2303 | Plausible / 50% | Enabled / transport reached |
| SEC / DTI | 1,696 / 0.93 | SEC | Incompatible / 0% | Disabled / stopped |
| DTI / Mayor's Permit | 881 / 0.93 | DTI | Incompatible / 0% | Disabled / stopped |

Every scan completed successfully. The three distinct accepted cue minima (issuer / heading / structure) are DTI **0.96 / 0.95 / 0.95**, SEC **0.96 / 0.95 / 0.95**, Cainta **0.93 / 0.96 / 0.95**, Quezon City **0.96 / 0.92 / 0.96**, BIR **0.96 / 0.96 / 0.95**. Global thresholds remain unchanged at high >=0.85 and plausible >=0.65. The SEC uses independent incorporation-body structure instead of its 0.73 reference label. BIR issuer evidence includes the short BIR indicator; the full agency phrase is not claimed as recovered. The printed form number has separate taxpayer structure. These cues establish preliminary family relevance, never government issuance or authenticity.

Safe reason codes: DTI and SEC `uncertain_field`; Cainta `business_identity_conflict`, `date_conflict`, `uncertain_field`, `low_recognition_confidence`; Quezon City `business_identity_conflict`, `uncertain_field`, `low_recognition_confidence`; BIR `uncertain_field`, `low_recognition_confidence`; both wrong-slot controls `wrong_document_type`, `uncertain_field`. Synthetic comparison names/addresses/dates remain deliberately different from the specimens. Missing/redacted identities and unreadable dates were not invented or forced to match. Not every printed expired date was reliably extracted; this round does not certify date completeness.

**No supplied specimen remains unresolved or falsely blocked in this round.** Unlike the historical wrong-slot observations, both final wrong-slot results follow confident incompatible-family recognition. All five originals' SHA-256 values remain unchanged. Evidence: `after-real-results.json`, `after-real-source-inventory.json`, `after-real-final.log`, `family-diagnostics.json` and `acceptance-check.json` under `output/ocr-real-correction/`.

### Negative controls, fresh regressions and build

The final actual-engine form matrix passed **20 scenarios** in Chromium 151.0.7922.34, including synthetic selfie-like portrait, cartoon/meme, blank, random scene and unrelated text (all replacement-required, disabled button and stopped handler); wrong supported families (replacement required); supported ID families including UMID (recognized and eligible); unsupported plausible ID (uncertain and eligible); 100%/50%/0% matching examples; pending scans; active replacement/removal; and blocked worker resource (distinct technical failure, no score, transport allowed). No previously blocked negative became eligible. A completed empty read remains a relevance failure, not `ocr_failed` or a worker exception. Safe per-case counts/confidences, states and handler outcomes are in `negative-browser-final/browser-results.json` and the final browser log.

The first matrix attempt stopped at the UMID regression; its failed log/state are retained separately under `negative-browser/`. The fix preserves its prior reading configuration rather than lowering confidence or relaxing the family assertion. Earlier focused regressions also failed meaningfully before their corresponding fixes: matched-word/wrapped-heading cases (2 failures), bounded preparation/AUTO cases (3), city competition/SEC structure/2303 heading cases (3), and ID layout/caller cases (2). Distant-heading and conflicting-pass controls continued passing. Final assertions were not weakened.

Fresh verification reran both complete frontend/Laravel commands listed in **Automated verification** above, with exactly the same affected paths:

- Frontend: **15 files / 214 tests passed**, exit 0. Includes owner reader/recognizer/hook/form, original upload/metadata/age/supporting-document behavior, DropZone, customer helper defaults and Super Admin views. New tests use synthetic strings/geometry, not real specimen values.
- Laravel: **113 tests / 815 assertions passed**, exit 0. Includes optional evidence validation, immutable persistence/replay/rollback, OTP/location, signed resubmission, private access and explicit per-score approval/rejection. No PHP production source changed.

```powershell
node --test scripts/prepare-owner-ocr-assets.test.mjs
node scripts/prepare-owner-ocr-assets.mjs --check
pnpm run build --outDir output/ocr-real-correction/production-build
node scripts/prepare-owner-ocr-assets.mjs --check
```

Packaging: **4 tests passed**. Both integrity checks passed for `tesseract-7.0.0-core-7.0.0-eng-1.0.0`. Production build exited 0 in **45.95 seconds**. Its manifest confirms the owner page's **40 static chunks exclude the reader**, which remains a dynamic entry. Existing tracked `public/build` was not used for output. The existing 24-file local OCR runtime archive was re-opened and every archived file's SHA-256 matched the prepared `public/ocr/` bytes after the build (`build-archive-check.json`). Thus ignored assets can be included in a release archive; Hostinger delivery is still untested. No dependency/version or asset-packaging policy changed.

The browser fetched the local worker, selected relaxed-SIMD core and English language model with no external OCR requests or runtime errors; final synthetic verification had no HMR reload. Normal OTP verification used the isolated loopback SMTP capture and the normal application middleware. Final Inertia transport was intercepted after the real handler/confirmation/CSRF path, preserving original-file hashes and preventing backend registration writes or notifications. Handler stops were tested independently of the disabled button. This does not constitute a persisted browser registration or an external-email delivery test.

### Changed files and sequential review

This correction changes only these six existing source/test files and this report, relative to their before-edit snapshots:

1. `resources/js/Pages/UserSide/Auth/shopOwnerDocumentRecognition.ts` — matched-word/adjacent-line evidence, heading-qualified competition, SEC structure and BIR form-number cues.
2. `resources/js/Pages/UserSide/Auth/registrationOcr.ts` — opt-in certificate preparation/AUTO within existing bounds; ID/customer defaults preserved.
3. `resources/js/Pages/UserSide/Auth/useShopOwnerOcr.ts` — one slot-based layout option at the existing reader call.
4. `resources/js/Pages/UserSide/Auth/__tests__/shopOwnerDocumentRecognition.test.ts` — eight added synthetic confidence/layout/family regressions.
5. `resources/js/Pages/UserSide/Auth/registrationOcrOwner.test.ts` — certificate bounds/AUTO and unchanged ID configuration.
6. `resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx` — reader layout dispatch regression.

Output-local runners, safe evidence, failed attempts and build output are development artifacts, not new production endpoints or release files. `ShopOwnerOcrStatus.tsx`, `ShopOwnerRegistration.tsx`, the payload helper, backend, package files, `.env` and schema were not edited by this correction. Their earlier uncommitted changes remain present.

Review was sequential in the main agent:

| Required gate | Result |
| --- | --- |
| Simplify / ponytail | Pass: reuse the existing reader, family definitions, confidence minimum, queue and score contract; one bounded read, no extra model/pass/dependency. |
| Standards, approved behavior, risks | Pass for this correction: independent localized cues, no score-based admission change, preserved original uploads and manual authority; corrected specimen mapping explicitly supersedes invalid historical claims. |
| TS/React quality | Pass by inspection: typed optional reader layout, small internal span helper, bounded adjacent windows and existing hook lifecycle. No new `any` or production diagnostic export. Compiler/lint not configured or claimed. |
| Karpathy | Pass: observed failing regressions and actual-engine comparison before fixes; six-file source/test delta and explicit limits. |
| Code splitting | Pass: existing dynamic reader import and fresh production manifest traversal; no new eager engine import. |
| Gauge improvements | Observed: five formerly unresolved originals now classified; SEC/BIR false blocks and DTI wrong-slot miss removed; synthetic controls preserved. Population accuracy, fraud rate, latency and device memory not measured. |
| Security / Laravel boundary | Pass within tested scope: original private uploads, report bounds, OTP, viewed/reviewer requirements and final decisions remain unchanged; no raw OCR or extracted values added to persisted data/logs. Existing untrusted-browser limit remains. |
| Verification before completion | Pass for the scoped defect only: fresh real-engine form outcomes, 214 frontend tests, 113 Laravel tests, packaging/build/integrity checks; remaining release checks explicitly open. |

Reuse/dead-code review: existing personal-name helpers, fixed scoring, cleanup/deadline handling, submission guards and Laravel services remain authoritative. No new UI, parser framework, duplicated queue or abandoned pixel sampler was added. All new production helpers/options have callers and regression coverage; no unfamiliar code was deleted. This task's findings stay in this report; unrelated learning-log changes are untouched.

**Limits and development status:** The actual five originals pass this scoped family/relevance gate, but they are user-supplied real-format images, not authenticated issuances or a representative accuracy dataset. Negative images remain procedural synthetic representations: no real camera selfie, phone landscape, permitted ID original or two Cavite-LGU originals was supplied. A weak genuine scan with no positive cues can still require retaking; medium plausible evidence remains uncertain/eligible, and genuine technical failures remain nonblocking. Browser code can be bypassed and cannot establish visual selfie identity or authenticity. Persisted browser registration, signed-resubmission/Super Admin browser journeys, full-project suites, other browser/core paths, mobile memory, Hostinger/CSP/subpath checks and external inbox delivery remain unverified here. The branch stays in development. No push, merge, deployment, production migration or automatic decision occurred.

Final hygiene: `git diff --check` passed. New-report `git diff --no-index --check -- /dev/null docs/operations/2026-10-22-shop-owner-ocr-relevance-fix.md` had no whitespace findings. `git diff --quiet -- .env package.json pnpm-lock.yaml public/build app database` exited 0; protected production/backend/package/build paths remain unchanged. No staging was performed. Existing frontend warnings (Node localStorage option and test-environment canvas/map configuration) and the unresolved-at-build geometric-pattern SVG warning are not new feature failures; no compiler/lint pass is claimed.

Local handoff: the dedicated disposable PHP listener on 8017 was stopped after testing. Normal Vite HMR was restored on 5187 using the existing output-local manual configuration. The ordinary PHP listener on 8000 was absent, so it was restored after read-only checks confirmed the existing development environment and local database configuration. No `.env` edit or database initialization was performed. `http://127.0.0.1:8000/shop-owner-register` and the Vite client returned HTTP 200, with the page referencing this worktree frontend. Hard-refresh an old tab. This availability check does not prove external OTP delivery or complete a registration.

## Loading Swal follow-up - October 22, 2026

Requested behavior: show OCR loading feedback like customer registration. The customer page uses a custom progress overlay; the requested owner implementation uses the existing shared `UserModal` SweetAlert styling and built-in spinner. One **Checking your document** dialog covers queued/loading/recognizing required documents, disallows outside-click/Escape dismissal and closes when all work settles, fails, is removed or the page unmounts. Cleanup closes only its own popup, preserving replacement alerts. The four redundant required-file **File Attached** dialogs were removed so they cannot replace the loading popup. Existing original previews, safe filenames, inline outcomes, supporting uploads, relevance/score/submit guards and technical-failure fallback remain authoritative.

Only `ShopOwnerRegistration.tsx`, its existing `ShopOwnerRegistrationOcr.test.tsx` and this report changed for this follow-up. Before-edit copies are under `output/ocr-loading-swal/`. No customer, reader, recognizer, backend, package or environment code changed.

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOptionalDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx
python output/ocr-loading-swal/verify_loading.py
pnpm run build --outDir output/ocr-loading-swal/production-build
git diff --check
```

Fresh results: **4 frontend files / 33 tests passed**, actual Chromium/Tesseract browser check passed, production build exited 0 (**42.61 seconds**) and diff hygiene passed. Two new lifecycle tests failed before implementation; the redundant attachment-popup assertion also failed before its removal. The actual browser verified visible dialog/spinner, blocked outside/Escape dismissal, one popup across queued DTI/permit scans, correct completed families, closure after completion and closure after a genuine blocked-worker technical failure. The synthetic-only screenshot `loading.png` was visually inspected. Normal OTP used loopback SMTP, with no registration submitted. The first runner attempt released a paused request twice; a subsequent retry correctly hit the existing OTP rate limit. After correcting runner ordering and respecting the cooldown, the final run passed with no runtime errors. Neither attempt justified changing OTP or app behavior.

Sequential review: simplify, Standards/Spec/risk, TS/React, Karpathy, reuse and dead-code inspection passed for this presentation-only delta. Shared modal/spinner and current OCR states were reused; no heavy import, new dependency, data sink, authorization change or score change was introduced. The observed improvement is visible scan feedback, not measured recognition/performance improvement. Existing backend/accuracy/release results above were not rerun or promoted by this change; full Laravel suites and mobile/Hostinger checks were not needed to establish this UI result and remain unverified in this follow-up. No compiler/lint claim is made. The disposable test listener was stopped; the existing manual app and Vite listeners were left available. No deployment, migration, push, merge or staging occurred.

## Spinner centering and scan-result Swals - October 22, 2026

The preceding loading follow-up missed two requested presentation details: the shared `.swal2-actions` rule uses `justify-content: stretch !important`, pushing the spinner left, and the loading effect closed without presenting a result alert. An OCR-popup-only override now centers the action row and loader margins. Other SweetAlert layouts retain their shared styling.

After the current scan batch settles, a result Swal now reuses the existing inline status text:

| Outcome | Result alert | Existing admission behavior |
| --- | --- | --- |
| Irrelevant image or reliably incompatible family | Error: **Invalid document image**, with the approved replacement guidance | Replacement still required. |
| Plausible document with conflicts or uncertainty | Warning: **Document needs admin review** | Remains submittable under existing rules, including 0% field conflicts. |
| Technical OCR failure | Info: **Unable to check document** | Existing nonblocking fallback remains. |
| Applicable automated checks match | Success: **Document check complete**; explicitly retains admin review | No authenticity claim or automatic approval. |

The page tracks only newly selected/scanning slots, consumes each batch once and gives invalid results priority. Older invalid files cannot mislabel a newer successful scan. Metadata edits cannot repeatedly reopen result alerts. Removal of unfinished files still clears the loader without inventing a result. Immediate unavailable-asset results also use the current slot status. Recognition, confidence thresholds, scoring, signed endpoints and submission guards were not changed.

Changed paths: `resources/css/app.css` (scoped centering), `ShopOwnerRegistration.tsx` (result notification lifecycle), `ShopOwnerOcrStatus.tsx` (export/reuse the existing safe message function), `__tests__/ShopOwnerRegistrationOcr.test.tsx` (seven regressions), and this report. Prior dirty versions were copied to `output/ocr-result-swal/before/`.

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOptionalDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx
python output/ocr-result-swal/verify_results.py
pnpm run build --outDir output/ocr-result-swal/production-build
git diff --check
```

Fresh results: **4 frontend files / 40 tests passed**, actual-browser check exited 0, production build exited 0 (**26.90 seconds**), and whitespace/protected-file checks passed. The seven new tests first failed on missing result alerts, then passed without changing their assertions. No compiler/lint check is configured or claimed. Existing Node/local map test warnings and the geometric-pattern SVG build warning remain.

Actual Chromium 151.0.7922.34 with local Tesseract verified spinner/popup/viewport centers at **720/720/720px** for a 1440px viewport and **195/195/195px** for 390px. Real-engine blank and procedural selfie scans showed the invalid-image Swal; a National ID in the DTI slot showed the same replacement error; a 0% DTI field conflict showed review guidance; a 100% matching synthetic DTI showed completion with manual-review wording. The queued DTI/permit batch produced review guidance, and a genuinely blocked worker produced the separate nonblocking info alert. A metadata edit did not repeat the result. No runtime errors or registration submission occurred. Normal OTP used the isolated loopback SMTP flow. `centered-390.png` and `invalid-result.png` were visually inspected; all test images are synthetic. Evidence is `output/ocr-result-swal/browser-results.json`, screenshots and logs.

Sequential simplify, Standards/Spec/risk, TS/React, Karpathy, reuse/dead-code and verification reviews passed for this scoped UI change. The existing message helper, scan states and modal were reused; no new dependency, raw-data output or authority path was added. The centering rule is limited to the owner OCR loader. Heavy imports/lazy loading are unchanged; no bundle restructuring was necessary. Functional UI behavior improved; accuracy/performance was not measured. Laravel/full-project suites, actual mobile hardware and Hostinger were not rerun for this presentation change. Existing limits elsewhere in this report remain open. The disposable test listener was stopped; normal manual testing remains available. No backend/environment/package change, deployment, migration, push, merge or staging occurred.
