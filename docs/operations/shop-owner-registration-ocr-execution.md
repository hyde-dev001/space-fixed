# Shop-owner registration OCR execution record

Date: 2026-10-21

Execution follows the approved October 21 specification and implementation plan. OCR remains browser-only and untrusted. Super Admin approval/rejection is still the sole decision path. No deployment, push, merge, or production migration is authorized.

## Phase 0 - Isolated baseline

- Branch/worktree: `codex/shop-owner-registration-ocr`, `.worktrees/shop-owner-registration-ocr`.
- Approved design copied from the original workspace and verified at SHA-256 `AFF1C92F87DF8A54A80C49C91A8CF35DBBF447B548A3A6844AC6ADE6172E6F27`.
- Original workspace inventory (592 modified/untracked paths and SHA-256 values) is stored in `output/shop-owner-ocr/original-workspace-baseline.json` in this untracked local worktree output area; it was intentionally not staged. No root user edits were copied or rewritten.
- A fresh original-workspace comparison found the same 592 paths, zero path/status differences, zero file hash drift, zero staged paths, and the same branch/HEAD/spec hash as the baseline.
- Clean checkout initially lacked `vendor/` and `node_modules/`. Locked dependencies were installed in the worktree with `composer install --prefer-dist --no-interaction --no-scripts` and pnpm 11.14.0 `install --frozen-lockfile`. Composer's optimized autoload generation completed; Composer's application lifecycle scripts did not run. The first Composer attempt was interrupted during slow optimized classmap generation; `composer dump-autoload --no-scripts --no-interaction` then completed successfully.
- Test database isolation is confirmed by `phpunit.xml`: APP_ENV testing, SQLite `:memory:`, array cache/session/mail, and synchronous queue. Test commands explicitly set the SQLite testing environment. No `.env` was read or changed.
- Focused frontend baseline: `pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts` — pass, 2 files, 8 tests. Node emitted an environment warning that `--localstorage-file` had no valid path.
- Laravel baseline: `php artisan test tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php --stop-on-failure` — exit 0, 32 tests, 220 assertions, 32 PHPUnit warnings. PHPUnit's direct `ShopDocumentInvariantTest.php --display-warnings` rerun passed 4 tests/13 assertions without those warnings. The warnings are retained as baseline evidence; no production validation was changed to suppress them.
- Pinned customer helper/test paths were verified in Git object `6540002bdfb0fd63d5f7f643f225343facc8ac77`; the approved helper dependency versions remain reference facts only until the package phase.
- No application code, migration, OCR asset, or dependency change is made in Phase 0.

## Later phase evidence

Phase-by-phase changes, focused checks, acceptance coverage, browser asset requests, and limitations are recorded below as each phase completes. Do not mark a phase complete without its listed checkpoint evidence.

## Phase 1 - Evidence contract and categorical scoring

- Added shared synthetic JSON fixtures for TypeScript/PHP score parity and pass-local evidence. No document images or personal values are present. The README restricts later image fixtures to permitted/redacted or visibly synthetic documents.
- Added the lazy-independent TypeScript contract module and Laravel screening service. Both derive only 0%, 50%, 100%, or null per document; require high local evidence for match/conflict; keep absent/weak fields uncertain; require independent signals for document type; and preserve the fixed four-document conflict/incomplete aggregation behavior.
- Laravel validates the exact allowlisted entry/check/reason structure, selected slot/type association, conditional BIR proprietor check, reference-day shape, confidence bounds, and the 32,768-byte raw UTF-8 envelope before decoding. Client scores, decision/reviewer fields, and OCR text are rejected. Browser score/evidence remains applicant-controlled.
- Plan refinement: document/date metadata-dependent non-expiring and reference-day rollover rules move to the recognition/persistence phases, where those existing form fields and server context can be supplied. The accepted architecture and score labels are unchanged.
- Focused frontend: `pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/shopOwnerScreening.test.ts` - pass, 1 file/25 tests. Node emitted the existing `--localstorage-file` warning.
- PHP focused: `php artisan test tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php --stop-on-failure` - exit 0, 14 tests/56 assertions; runner output marks each test `WARN` and includes per-test fixture `file_get_contents` path notes. Direct `vendor/bin/phpunit --display-warnings tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php` - pass, 14 tests/56 assertions, no warnings. The CLI-runner warning behavior matches the earlier baseline pattern and remains unsuppressed.
- TDD evidence: the initial PHP suite failed on the explicit missing-service assertion before implementation. The initial TypeScript attempt failed during module loading, so it is not counted as an assertion-level red test.
- Not yet verified in Phase 1: live OCR, non-expiring/date-rollover context, admin reason labels, persistence, registration admission, and decision isolation. These remain in their owning later phases.

## Phase 2 - Reader reuse and local runtime package

- Reused the pinned customer reader and personal-name matcher while retaining customer defaults. Owner screening uses its separate full-image path, localized pass evidence, cancellation/deadline handling, explicit same-origin asset paths, and a 1,800-pixel working-image edge limit. The uploaded original remains unchanged.
- Pinned Tesseract.js/core 7.0.0 and English data package 1.0.0. `dev` and `build` explicitly prepare the ignored `public/ocr/` runtime directory; the versioned manifest records local paths and checksums. The package includes the worker, all compatible core JS/WASM variants, English trained data, and notices (24 files, 48,335,784 bytes in this build).
- `pnpm run test:frontend resources/js/Pages/UserSide/Auth/registrationOcr.test.ts resources/js/Pages/UserSide/Auth/registrationNameMatch.test.ts` passed (2 files, 19 tests). `node --test scripts/prepare-owner-ocr-assets.test.mjs` passed (4 tests). `pnpm run prepare:owner-ocr` and `node scripts/prepare-owner-ocr-assets.mjs --check` passed.
- Worker cleanup after unresolved startup is bounded by an explicit confirmation wait; if termination cannot be confirmed, queued work is made unavailable and no new worker starts. Customer-specific duplicate-side/admission behavior was not ported.

## Phase 3 - Recognition and transient lifecycle

- Implemented conservative field-local recognition for DTI, SEC, Mayor's Permit, BIR Form 2303, and supported government ID families, with localized evidence, role-aware matching, conservative date/address handling, and uncertainty for ambiguous layouts.
- Added hand-authored, watermarked SVG fixtures with invented values and no official artwork: DTI, SEC, two permit layouts, BIR Form 2303, National ID, driver's license, and unsupported government ID. The fixtures are synthetic evidence only.
- Frontend recognition/reader/hook check passed: 4 files, 55 tests. Tests cover field reliability, the five score/aggregate outcomes, timeout and cleanup behavior, single-worker sequencing, replacement/removal, form-context changes without rereading, and submit snapshots.
- No OCR text or extracted personal values were recorded in this execution log. The test suite does not claim OCR accuracy.

## Phase 4 - Private immutable report persistence

- Added one nullable `shop_documents.ocr_screening` JSON column with no backfill, index, or owner-level aggregate. Reports are sanitized and attached inside the existing locked version-batch transaction with server file checksum and comparison-context binding; existing replay, rollback, cleanup, and retained-file inheritance behavior is preserved.
- Laravel validation enforces the fixed envelope and score rules. OCR data is excluded from flashed old input and default model serialization. Pending documents still report `metadata_unverified`; report evidence cannot fill reviewer metadata or affect status.
- Focused PHP verification passed: 55 tests, 282 assertions, with 8 PHPUnit deprecations. PHP syntax checks and `git diff --check` passed. Registration tests cover 100%, 50%, 0%, unavailable and omitted OCR, malformed envelopes, and signed resubmission while preserving existing requirements.
- No production/shared database migration was run.

## Phase 5 - Registration and signed resubmission integration

- Both fresh registration and signed rejected-application resubmission receive the same optional, validated local-asset bootstrap. Missing/corrupt runtime assets produce an unavailable state. The form uses the existing file controls and upload request, reports accessible preliminary progress, and never waits for OCR before submission.
- Completed reports are snapshotted only when current; unfinished/stale/invalid reports are omitted. Relevant field edits recalculate retained evidence. Replacement/removal clears old evidence and preview references.
- Focused frontend tests passed: 93 tests across 7 files. Registration/bootstrap/email feature tests passed: 23 tests, 180 assertions, with 8 PHPUnit deprecations. The original file payload, signed route, missing-asset behavior, and submit-during-scan behavior have focused test coverage.

## Phase 6 - Super Admin preliminary review

- Added an allowlisted preliminary OCR projection and score/reason display alongside the existing document viewer and server validity information. Historical reports remain dated snapshots, and only the current application candidates contribute to the aggregate.
- The review view says authenticity is not independently verified. It does not mark documents viewed, confirm metadata, alter access, or change approval/rejection controls. The existing decision service and protected viewer were not modified.
- Focused frontend review tests passed: 2 files, 11 tests. Review, decision-isolation, existing approval, workflow, and private-access tests passed: 46 tests, 389 assertions. PHP syntax and diff hygiene passed.

## Phase 7 - Build, browser, and acceptance evidence

- Production build passed with Vite 7.3.1: 3,770 modules transformed in 33.44 seconds. Cold-cache browser inspection found no worker/core/language or lazy reader request before invoking OCR. Owner OCR then loaded only the local worker, selected core, and language asset; no OCR CDN fallback was observed.
- Real browser-engine checks used Chromium 151.0.7922.34 on all eight synthetic SVG fixtures rasterized to browser PNGs, and WebKit 26.5 on a synthetic DTI. Chromium read the eight fixtures, including the two distinct permit layouts and multiple ID layouts; WebKit completed its DTI read. Results were reduced to type-signal booleans, confidence bands, and counts; extracted text was not retained. These checks establish functional engine operation on synthetic layouts only, not accuracy or authenticity.
- Local responses were HTTP 200: worker JavaScript (111,307 bytes, `application/javascript`), selected relaxed-SIMD core JavaScript (~3.9 MB, `application/javascript`), and English model gzip (2,952,873 bytes, `application/gzip`). A forced local worker 404 returned an OCR failure and cleaned up without an external request. A separate language-path 404 browser attempt did not complete its harness cleanup timeout and is inconclusive; unit tests cover unavailable assets.
- `ShopOwnerOcrBootstrapTest` covers a `/portal` base path and passed in the backend regression suite. The actual browser was served at root, so deployed subpath routing is not verified end-to-end. The local response had no CSP header; Hostinger CSP/worker/WASM compatibility is unverified.
- A temporary Hostinger-package smoke ZIP included both `public/build/` and ignored `public/ocr/` with all 24 OCR files present (21,983,884 bytes). This checks archive structure only; no Hostinger deployment or target-server static-file check occurred. The generated `public/ocr/` is retained locally and Git-ignored. Vite-generated `public/build/` outputs were restored to the worktree's HEAD after build verification so they do not pollute the feature diff.
- A disposable local Laravel registration UI run could not complete the normal OTP flow because test email delivery was unavailable to the browser harness. No bypass was added, no registration was created, and no protected Super Admin browser session was available. Full fresh-registration, signed-resubmission, and authenticated admin browser journeys remain unverified; focused React/Laravel tests cover their code paths.
- Broad existing backend regression command passed (108 tests/warnings, 634 assertions, exit 0). The full frontend suite reported 989 passed and one failure in unrelated contradictory Repairs navigation tests: one test requires the `Repairs` heading while another requires it absent. `composer test` did not finish: attempts hit the 300-second Composer timeout and PHPUnit's 512 MB child memory limit, with failures reported in unrelated ERP, logistics, suspension, maintenance, manager, notification, and repair suites. These failures were not changed and cannot be declared pre-existing without a complete baseline run.
- OCR score/fraud accuracy, production latency, device peak memory, supported CSP policy, deployed Hostinger behavior, and real-user document performance are not measured. The repository has no configured TypeScript compiler or lint script; neither was claimed.

### Acceptance status

| Group | Result | Evidence and remaining limit |
| --- | --- | --- |
| A1 Recognition | Functional synthetic coverage | Recognition tests plus actual Chromium reads of the eight synthetic layouts and WebKit DTI. No real or statistically representative documents. |
| A2 Roles and matching | Covered in focused tests | Role/name/address rules are in recognizer tests. Integrated registration matching was not exercised through an authenticated browser journey. |
| A3 Recognition errors | Covered in focused tests | Localized-confidence, ambiguity, weak/missing evidence, and reader behavior tests pass; no measured real-world error rate. |
| A4 Dates | Covered in focused tests | Expiry, future/reversed dates, metadata conflicts, ambiguity, non-expiry, rollover and pending validity are covered in frontend/backend suites. |
| A5 Scoring | Covered | Shared TS/PHP fixtures and admin tests cover 0/50/100, nonnumeric states, conflict precedence, and aggregate examples. |
| A6 Nonblocking admission | Backend/frontend tests pass; browser partial | Submission/error/failure cases are automated. Full wizard submit during OCR and OTP-authenticated flow remain unverified in browser. |
| A7 Browser lifecycle | Unit and hook tests pass; browser partial | Queue, cleanup, cancellation, replacement/removal, stale contexts and snapshots are tested. Full integrated page lifecycle was not browser-driven. |
| A8 Deployment | Build/package checks pass; production partial | Local assets, checksums, MIME, cold cache and archive inclusion are checked. Hostinger, production CSP, and browser subpath delivery remain unverified. |
| A9 Persistence | Covered | Envelope, privacy, binding, replay, rollback, legacy null rows and hidden serialization are tested. No production migration run. |
| A10 Version isolation | Covered in backend tests | Replacements/retained reports, context, replay and historical isolation are exercised; authenticated admin browser presentation is unverified. |
| A11 Authority and privacy | Backend covered; browser partial | Decisions at each score, viewer authorization, viewed/reviewer requirements and protected serialization pass focused tests. No authenticated review browser session. |
| A12 Regressions | Partial | Focused and broad affected backend suites pass; full frontend has one unrelated contradiction and `composer test` is incomplete/fails as described above. |

### Phase 7 gate result

Phases 0-6 are implemented and their focused checks passed. Phase 7 collected build, package, direct OCR-engine, and affected regression evidence, but the authenticated end-to-end browser journeys and complete repository-wide test gates remain unresolved. Do not describe this branch as fully verified or deployment-ready until the A6-A8/A11 browser limitations and failed/incomplete full-suite gates are resolved or explicitly accepted by a later release decision.

## Post-rebase verification - refreshed `solespace-b` base (2026-10-22)

- The OCR worktree was initially based on `fix/logistics-hardening-follow-up` at `7ba4d5b36ec509a0ddf9c04a2e99d5fe97d394cd`, not the requested `solespace-b`. The latest fetched `origin/solespace-b` was `e0d8f18143eaeecb7e4e3b9d264ca2f24020104a`.
- At the rebase checkpoint, the worktree `.worktrees/shop-owner-registration-ocr` was on branch `codex/shop-owner-registration-ocr-solspace-b` at `c541f1730461a0e7613349c758763852b463edc2`. `git merge-base --is-ancestor origin/solespace-b HEAD` passed and `git rev-list --left-right --count origin/solespace-b...HEAD` returned `0 9`: the nine OCR feature commits were replayed above the fetched base. A follow-up commit contains only target-branch test-fixture compatibility and this verification record. The old feature tip remains on `backup/shop-owner-registration-ocr-pre-solspace-b-20261022`; the original branch ref was not deleted.
- Latest-base integration required updating existing test fixtures for the target branch's required applicant age/address fields and explicit admin page permissions. Production validation, private-document authorization, and the rule denying unassigned admins remain unchanged; the unassigned-admin rejection case still passes.
- Fresh frontend verification on this branch: `pnpm run test:frontend --reporter=dot` passed, 309 files and 2,000 tests. This supersedes the earlier full-frontend result recorded above for the old base.
- Fresh focused backend verification: `php artisan test tests/Feature/SuperAdmin/ShopOwnerOcrReviewTest.php tests/Feature/SuperAdmin/ShopOwnerOcrDecisionIsolationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/ShopDocuments/ShopOwnerOcrPersistenceTest.php tests/Feature/ShopDocuments/ShopOwnerOcrBootstrapTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php --stop-on-failure` passed, 90 tests and 724 assertions. PHPUnit reported existing doc-comment metadata deprecations.
- On this branch, `pnpm run build` passed with 3,850 modules transformed in 28.68 seconds. The generated Vite output was restored after checking; ignored `public/ocr/` assets were retained. `node --test scripts/prepare-owner-ocr-assets.test.mjs` passed 4 tests, and `node scripts/prepare-owner-ocr-assets.mjs --check` verified the versioned local runtime. A package-archive smoke check included both `public/build/` and ignored `public/ocr/`.
- A local browser smoke on this branch used a synthetic registration image and the actual Tesseract engine. The worker, selected core, and English language file returned HTTP 200 with expected MIME types; the scan completed without external OCR requests or page errors. This is functional synthetic evidence, not a real-document accuracy result. Earlier fixture coverage across Chromium/WebKit remains limited to synthetic watermarked layouts.
- The complete `composer test` suite was not rerun after rebasing; earlier attempts timed out or reached PHPUnit's configured child memory limit. Authenticated registration/resubmission/admin browser journeys, local OTP email delivery, production Hostinger static-file delivery, deployed base-path behavior, and Hostinger CSP/worker/WASM compatibility remain unverified. Phase 7 is still incomplete and this work is not deployment-ready.
- No push, merge, deployment, production database migration, `.env` edit, or change to the original workspace's files was performed as part of the rebase.
