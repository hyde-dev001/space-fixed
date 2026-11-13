# Shop Owner Registration OCR Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task by task, sequentially under `AGENTS.md`. Steps use checkbox tracking. Do not dispatch subagents or begin execution without the user's separate approval.

**Goal:** Add browser-only preliminary document screening to existing shop-owner registration and Super Admin review while preserving all existing admission and decision requirements.

**Architecture:** Reuse the smallest compatible subset of customer Tesseract.js reading and matching. Browser-local recognition produces bounded untrusted evidence; Laravel validates its structure, derives categorical scores, and attaches a private report to an immutable document version. Existing Super Admin review remains the exclusive final decision workflow.

**Tech Stack:** Laravel 12/PHP 8.2, Inertia 2, React 18, TypeScript, Vite 7, pnpm, Tesseract.js 7, Vitest/Testing Library, PHPUnit, Node built-in tests for asset preparation, and available browser-testing tools.

---

## Authority and current status

Planning baseline: 2026-10-21. The user approved the [revised specification](../specs/2026-10-21-shop-owner-registration-ocr-design.md), then approved execution in an isolated worktree. On 2026-10-22 the user approved a scoped relevance-gate revision; its implementation and test evidence are recorded below and in the [execution record](../../operations/shop-owner-registration-ocr-execution.md). Phase 7 remains incomplete because authenticated registration/resubmission/admin browser journeys and production-host compatibility checks are unresolved.

The authoritative specification is the approved working-tree revision, not the older version of that file in HEAD. Its SHA-256 at planning time is `AFF1C92F87DF8A54A80C49C91A8CF35DBBF447B548A3A6844AC6ADE6172E6F27`. Re-read the approved file if its hash changes; do not silently substitute the older committed design.

The isolated execution checkout is `.worktrees/shop-owner-registration-ocr`, branch `codex/shop-owner-registration-ocr-solspace-b`, HEAD `005f1d9cebbdba64cd55ca453c0c1e84ba2477ea`; the fetched `origin/solespace-b` is an ancestor. Customer helper source reference: `6540002bdfb0fd63d5f7f643f225343facc8ac77` on `feat/customer-registration-identity-verification`.

The architecture is settled: browser Tesseract.js only; no VPS/server OCR, paid OCR API, government lookup, auto-approval, or auto-rejection. Scores remain preliminary and untrusted. Technical OCR failures do not block otherwise valid registration. The 2026-10-22 amendment pauses submission during scans and requires replacement for a successfully classified irrelevant image or reliably incompatible type; field conflicts and plausible uncertainty remain submittable. Malformed metadata actually submitted still receives validation errors.

## Repository grounding and protection boundary

Verified seams relevant to this plan:

- `app/Http/Controllers/UserSide/LandingPageController.php::shopOwnerRegister` renders the fresh owner registration page without OCR configuration today. `ShopOwnerAuthController::showResubmissionForm` renders the same component with signed resubmission data. Both need the same optional OCR bootstrap configuration.
- `ShopOwnerAuthController::register`, `resubmit`, and `registrationDocumentEntries` preserve canonical slots, explicit pending status, existing email/location/date/file validation, and retry limits. Add optional evidence to this flow rather than introducing another endpoint.
- `ShopDocumentLifecycleService::createPendingVersions` already owns staging, server checksums, locked batch creation, predecessor reuse, replay comparison, and rollback cleanup. Attach reports there. Keep renewal callers and the single-version path compatible with absent reports.
- `ShopOwnerRegistrationViewController::index/formatRegistration` explicitly selects owner/document columns and serializes allowlisted props. Its history must not be mistaken for the current OCR candidate set.
- `ShopOwnerRegistrationDecisionService` already owns locked explicit decisions, viewed-document/reviewer metadata requirements, audits, mail, and module provisioning. It is a protected reference, not a planned production edit.
- Current `vitest.config.ts` uses jsdom and `resources/js/test/setup.ts`, and includes `resources/js/**/*.test.{ts,tsx}`. `phpunit.xml` defines the testing environment with SQLite `:memory:`. There is no configured TypeScript compiler/lint gate to claim.
- `vite.config.js` uses the Laravel `resources/js/app.jsx` entry and clears its build output. None of the three customer OCR helpers or Tesseract dependency declarations is present in this checkout.
- Current `package.json`, `pnpm-lock.yaml`, `public/build`, the approved spec, billing files, existing plans/specs, and `docs/ai-learning-log.md` already contain unrelated/uncommitted work. Browser scripts/Playwright declarations visible in this checkout are also uncommitted; do not assume they exist in a clean implementation baseline.

Only this plan is authored in the planning task. During later execution, preserve every unrelated change, including changes to files this feature also needs. Never replace `package.json`/the lockfile wholesale, merge the customer branch blindly, stage all files, reset the workspace, or run a production database command as a test.

## Phase map and dependency order

| Phase | Deliverable | Depends on | Acceptance groups principally covered |
| --- | --- | --- | --- |
| 0 | Safe execution baseline, provenance, and existing-regression baseline | User approval to execute | A12; prerequisites for all groups |
| 1 | Fixed evidence contract, shared cases, TS/PHP normalization and scoring | 0 | A5, A9 |
| 2 | Minimal reader reuse and local runtime asset packaging | 1 | A3, A7, A8, A12 |
| 3 | Field-local recognition/matching and bounded OCR lifecycle hook | 1, 2 | A1-A4, A6, A7 |
| 4 | Nullable report schema, validated admission, atomic version persistence | 1 | A6, A9, A10, A11 |
| 5 | Existing owner form and signed resubmission integration | 2, 3, 4 | A6-A8, A10, A12 |
| 6 | Existing Super Admin review integration and decision isolation | 1, 4, 5 | A5, A10, A11, A12 |
| 7 | Acceptance audit, regressions, production build, browser verification, handoff | 0-6 | A1-A12 |

Execute these phases in the listed order with one main agent. Dependencies describe build/test prerequisites, not permission to dispatch parallel writers. Do not deploy a partial phase set.

## File-level structure

The following are proposed future changes, not files created by this planning task. If execution discovers an equivalent helper already exists, reuse it and record the adjusted path instead of adding a duplicate.

| Action | Exact path | Responsibility |
| --- | --- | --- |
| Create | `tests/Fixtures/ShopOwnerOcr/screening-contract.json` | Synthetic TS/PHP parity cases for bounded evidence, normalization, scores, and aggregation. |
| Create | `tests/Fixtures/ShopOwnerOcr/recognition-evidence.json` | Synthetic pass-local word/field cases, including ambiguity and name roles. |
| Create | `tests/Fixtures/ShopOwnerOcr/README.md` | Fixture provenance, permitted/redacted image inventory, expected results, and limits. |
| Create | `resources/js/Pages/UserSide/Auth/shopOwnerScreening.ts` | Small typed evidence contract, normalization/scoring/aggregation, safe serialization; no OCR import. |
| Create | `resources/js/Pages/UserSide/Auth/shopOwnerDocumentRecognition.ts` | Pure owner document indicators and role-aware localized matching; no worker or persistence. |
| Port if absent / extend compatibly | `resources/js/Pages/UserSide/Auth/registrationOcr.ts` | Required customer reader subset plus owner-only evidence, cancellation/deadline, bounded preparation, and asset-path options. |
| Port if absent / preserve if present | `resources/js/Pages/UserSide/Auth/registrationNameMatch.ts` | Existing personal-name matching behavior; no business-name fuzzy matching. |
| Create | `resources/js/Pages/UserSide/Auth/useShopOwnerOcr.ts` | Sequential latest-selection queue, generations, transient evidence, cleanup, and submission snapshot. |
| Modify | `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx` | Wire existing upload callbacks/form context to hook and accessible preliminary states. |
| Modify | `resources/js/Pages/UserSide/Auth/registrationDocumentPayload.ts` | Append safely serialized optional evidence while retaining existing multipart fields/files. |
| Create | `app/Services/ShopOwnerDocumentScreeningService.php` | Laravel structural validation, normalization/scoring, context binding, safe review projection/summary, and browser bootstrap. No OCR or new approval workflow. |
| Create | `database/migrations/2026_10_21_000001_add_ocr_screening_to_shop_documents_table.php` | One nullable JSON column. Check filename/order against the execution baseline before creation. |
| Modify | `app/Models/ShopDocument.php` | Explicit report cast, internal create allowlist, and hidden raw report. |
| Modify | `app/Services/ShopDocumentLifecycleService.php` | Attach or safely inherit reports at batch-version creation without changing transaction/replay rules. |
| Modify | `app/Http/Controllers/ShopOwnerAuthController.php` | Validate optional evidence for both admission paths; pass it to existing entries; supply resubmission bootstrap. |
| Modify | `app/Http/Controllers/UserSide/LandingPageController.php` | Supply fresh-registration OCR bootstrap only in `shopOwnerRegister`. |
| Modify | `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php` | Select internal inputs, safely project current/historical screening, calculate summary. |
| Modify | `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx` | Existing list/detail display, source disclosure, reasons, reference day; decision controls unchanged. |
| Create | `scripts/prepare-owner-ocr-assets.mjs` | Deterministic local asset copy and versioned manifest, with a read-only check mode. |
| Create | `scripts/prepare-owner-ocr-assets.test.mjs` | Node built-in tests using temporary fixture directories for packaging/check behavior. |
| Modify | `package.json`, `pnpm-lock.yaml`, `.gitignore` | Justified OCR packages, explicit preparation commands, generated runtime-output ignore rule. Preserve existing changes. |
| Generate later | `public/ocr/manifest.json` and `public/ocr/tesseract-7.0.0-core-7.0.0-eng-1.0.0/` | Public engine/model assets only, never uploaded documents or screening data. Generated versions reflect actual resolved packages. |
| Create | `docs/operations/shop-owner-registration-ocr.md` | Build/deployment prerequisites, preliminary-score meaning, failure fallback, verification evidence, and rollback notes. |

Production reference files to preserve: `app/Services/ShopOwnerDocumentRequirementService.php`, `app/Services/ShopOwnerRegistrationDecisionService.php`, `app/Services/ShopDocumentValidityService.php`, `app/Http/Controllers/PrivateSensitiveDocumentController.php`, existing auth/middleware/routes, and all customer persistence/admission policies. Modify a reference file only if a demonstrated integration defect requires the smallest compatible adjustment and record that finding before expanding scope.

## Contract decisions the implementation must follow

- Use exactly the approved four logical slots and required check sets. DTI/SEC selection remains independent of Individual/Business; supported-type recognition cannot narrow owner ID admission.
- Share synthetic case data between TS and PHP rather than generating one language's runtime code from the other or adding a schema framework. PHP reads `tests/Fixtures/ShopOwnerOcr/screening-contract.json`; frontend tests import/read that same file. It is test data, not an extra public endpoint or production configuration framework.
- Define finite 0..1 confidence, high >= 0.85 and medium >= 0.65 (otherwise low), fixed flags and thirteen approved reason codes, at most eight distinct reasons per entry, and a 32,768-byte JSON envelope limit across at most four entries. Use field-local evidence; document confidence is informational.
- Missing checks become uncertain. Invalid supplied types/keys/codes, prohibited scores/approval/reviewer fields, and contradictory structure fail validation. An unavailable client serializer omits evidence instead of blocking normal submission.
- Derive 0% from a reliable required conflict, 100% only when every applicable required check reliably matches, otherwise 50% when usable reading exists. No usable reading means no score. Preserve Not assessed, Unable to assess, Needs rescan, Assessment incomplete, and all five approved aggregate examples.
- Per-entry `assessment_state` is exactly `assessed` or `unable_to_assess`; absence means Not assessed, and stale context derives Needs rescan. The aggregate cases are `[100,100,100,100] -> 100`, `[100,50,100,100] -> 50`, `[100,0,100,100] -> 0`, `[100,null,100,100] -> incomplete`, and `[0,null,100,100] -> 0 plus incomplete`. Never average scores.
- Specify `detected_family` as the certificate/ID families used by the approved matrices, `other_document`, or null. A null/unsupported family cannot qualify type recognition for 100% or establish a wrong-type conflict by itself.
- Keep conditional BIR proprietor handling explicit and conservative. An unclear relationship keeps business identity uncertain; it cannot omit a required check to obtain 100%.
- Use server-provided reference day/shop timezone. Missing/unusable reference day makes temporal checks uncertain. A day rollover can downgrade temporal evidence without a registration rejection. Show stored date findings as dated snapshots beside existing validity fields; never rewrite history just because a day passes.
- Keep raw OCR text, word coordinates/text, names, addresses, ID/TIN/QR values in transient browser memory only. Existing required registration fields still travel through their normal form fields.
- Generate a stable server comparison hash from the relevant normalized form fields, selected type, applicable `registration_type`, date metadata, and report schema. Do not include current day, generic `updated_at`, email/phone, or unrelated form fields that would stale unchanged evidence. Owner-ID context excludes shop address; corporate SEC context excludes the representative name. Include proprietor context only where applicable. Business-type changes invalidate affected interpretations without introducing a new DTI/SEC admission restriction. The same service constructs and compares this hash; it is never browser authentication.

Required check sets remain fixed in both implementations:

| Document | Required checks |
| --- | --- |
| DTI | `document_type`, `business_identity`, `proprietor_identity`, `dates` |
| SEC | `document_type`, `business_identity`, `dates` |
| Mayor's Permit | `document_type`, `business_identity`, `business_address`, `dates` |
| Form 2303 | `document_type`, `business_identity`, `business_address`, `dates`; add `proprietor_identity` for a supported explicit sole-proprietor relationship |
| Valid ID | `document_type`, `owner_identity`, `dates` |

For semantic field results, reliability requires high localized confidence and complete/associated/unambiguous evidence; type results also need independent signals. Recognized non-expiring policy with `expiration_mode = none` relies on reliable type evidence rather than fabricated expiry confidence. An unknown policy remains uncertain. No browser-supplied applicability flag can remove a required check.

## Phase 0 - Protect the workspace and establish the execution baseline

**Dependencies:** Separate user approval of this plan. No implementation preparation is performed in the planning task.

**Affected areas:** Approved spec/plan snapshots and an isolated future checkout; no application behavior change.

- [x] Record `git status --short`, staged paths, HEAD, and the approved spec hash before editing. Inventory untracked paths as well as tracked changes; do not put secrets or full personal document contents in the record. Rechecking the original workspace after setup found the same 592 paths, zero path/status differences, and zero file hash drift.
- [x] Create an isolated checkout only after execution approval, using `superpowers:using-git-worktrees` and the already ignored `.worktrees/` convention. Suggested branch/path: `codex/shop-owner-registration-ocr`, `.worktrees/shop-owner-registration-ocr`. Recheck that both names are available and resolved paths remain inside the repository before creation.
- [x] Carry only the approved specification and this plan into that checkout as reviewable documentation; the current approved spec is uncommitted. Do not assume a worktree from HEAD contains it. Do not move unrelated work into the new branch.
- [x] Reconcile package/test-tool differences against the chosen baseline. Preserve the current root's package/lock/browser-tool changes rather than adopting or overwriting them implicitly. If a required baseline file is missing, document the exact difference before adding only what this feature needs.
- [x] Check dependencies inside the isolated checkout; worktrees do not inherit `vendor/` or `node_modules/`. If absent, install from the existing lockfiles with the commands below after execution approval. Suppress Composer lifecycle scripts during baseline installation so setup does not copy `.env` or invoke unrelated application setup. Do not update dependency versions to repair an unrelated baseline. Recheck that checkout's `AGENTS.md` and CodeGraph availability before source exploration.
- [x] Confirm test environment isolation from `phpunit.xml` and the test harness. Do not use production credentials/database, edit `.env`, or run `migrate:fresh`, database resets, or deletion commands. Use testing SQLite and framework fakes for backend tests.
- [x] Revalidate the pinned helper sources and their direct dependencies. Collect permitted/redacted fixture sources with provenance; do not claim representative OCR success until those fixtures are available and actually exercised.
- [x] Run the narrow existing registration/document/decision tests below as a baseline. Record failures as pre-existing where demonstrably so. Diagnose anything blocking the approved flow before coding around it; never weaken an invariant to make a baseline pass.

Future baseline commands, from the isolated checkout:

```powershell
git status --short
git diff --cached --name-only
git rev-parse HEAD
Get-FileHash -LiteralPath docs/superpowers/specs/2026-10-21-shop-owner-registration-ocr-design.md -Algorithm SHA256
git cat-file -e 6540002bdfb0fd63d5f7f643f225343facc8ac77:resources/js/Pages/UserSide/Auth/registrationOcr.ts
```

Only if required dependencies are absent, future baseline setup commands are:

```powershell
composer install --prefer-dist --no-interaction --no-scripts
pnpm install --frozen-lockfile
```

Then run the baseline checks in the isolated testing environment:

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
php artisan test tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php --stop-on-failure
```

**Checkpoint:** Correct approved spec is present; root work remains untouched; test DB is isolated; baseline results/provenance are recorded. Missing test infrastructure/fixtures are execution dependencies, not grounds to change the architecture.

**Risks and controls:** Wrong worktree/spec or dirty package overwrite -> explicit snapshots and path ownership. Inherited failing workflows -> baseline diagnosis and a recorded scope decision. Credential/document leakage -> synthetic identities and permitted redacted fixtures only.

## Phase 1 - Define evidence, normalization, and score parity before persistence

**Dependencies:** Phase 0. **Acceptance:** A5, A9; establishes shared expectations for A1-A4/A11.

**Files:**

- Create `tests/Fixtures/ShopOwnerOcr/screening-contract.json`, `tests/Fixtures/ShopOwnerOcr/recognition-evidence.json`, and `tests/Fixtures/ShopOwnerOcr/README.md`.
- Create `resources/js/Pages/UserSide/Auth/shopOwnerScreening.ts` and `app/Services/ShopOwnerDocumentScreeningService.php` with only the contract/normalization/scoring responsibilities needed at this point.
- Create `resources/js/Pages/UserSide/Auth/__tests__/shopOwnerScreening.test.ts` and `tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php`.

- [x] Write table-driven parity cases for all score and aggregate rules, confidence boundaries, missing/local-confidence evidence, BIR role uncertainty, unresolved family, empty/unavailable evidence, and recognized wrong-type evidence. Date-policy-dependent non-expiring and reference-day-rollover checks remain with Phases 3-4, where submitted date metadata is available.
- [x] Add negative envelope cases for invalid JSON, unknown slots/checks/reasons, duplicate/excess reasons, out-of-range/nonfinite confidence, prohibited score/decision/reviewer/raw-text fields, and UTF-8 byte counting. Exercise valid 32,767/32,768-byte envelopes, reject 32,769 bytes, and reject oversized multibyte input. Optional-field omission behavior remains with Phase 4 admission integration.
- [x] Run focused TS/PHP tests before implementation. PHP produced a meaningful missing-service failure; the initial TS run failed to load the not-yet-created module and is not counted as an assertion-level red test.
- [x] Implement minimal typed TS validation, per-document scoring, four-document summary, confidence bands, and safe optional serialization. Keep this module free of Tesseract/worker imports so page bootstrap does not fetch OCR.
- [x] Implement equivalent Laravel validation and score derivation in the feature service. It raises safe `ValidationException` messages under `ocr_screening` and checks the raw UTF-8 byte length before JSON decoding.
- [x] Use the existing Laravel `Tests\TestCase` for unit cases; no database setup is required for pure contract/scoring checks.
- [x] Enforce the fixed reason-code allowlist; missing/insufficient evidence cannot be upgraded by a supplied score. The fixed code-to-display-text mapping belongs to Phase 6's admin UI.
- [x] Rerun parity tests. Supplied score/decision/reviewer/raw-text fields are rejected; the service has no persistence or decision-writing path and browser evidence remains untrusted.

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/shopOwnerScreening.test.ts
php artisan test tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php --stop-on-failure
```

**Checkpoint:** The same fixed evidence cases produce matching TS/PHP normalized scores and incomplete-summary behavior; privacy and forbidden-field cases pass. No database column or registration decision change is required for this phase.

**Risks and controls:** TS/PHP drift -> common fixtures. 0% from unreadable text or page-level confidence -> explicit negative cases. Semantic trust inflation -> service docs and no approval side effects.

## Phase 2 - Reuse the reader and package the local browser runtime

**Dependencies:** Phases 0-1. **Acceptance:** A3, A7, A8, A12.

**Files:**

- Port the required reader/direct helper subset to `resources/js/Pages/UserSide/Auth/registrationOcr.ts` and existing personal-name helper to `resources/js/Pages/UserSide/Auth/registrationNameMatch.ts` if absent; preserve existing files/defaults if present in the execution baseline.
- Port applicable reader/name tests into `resources/js/Pages/UserSide/Auth/registrationOcr.test.ts` and `resources/js/Pages/UserSide/Auth/registrationNameMatch.test.ts`. Do not pretend omitted fingerprint/customer-flow tests ran.
- Create `scripts/prepare-owner-ocr-assets.mjs` and `scripts/prepare-owner-ocr-assets.test.mjs`; modify `package.json`, `pnpm-lock.yaml`, and `.gitignore` surgically.
- Generate only public runtime assets under `public/ocr/`; no private document ever belongs there.

- [x] Inventory `readRegistrationId` and its direct dependencies at the pinned source. Reuse full-image preparation, normalization, errors, and cleanup. Do not copy unused perceptual duplicate-side machinery, customer admission rejection policy, or an entire customer feature branch.
- [x] Write reader tests for default-call compatibility, owner-local word/pass evidence, timeout, cleanup, abandoned startup, oversized working-image preparation, and supplied local paths. Write packaging tests against temporary directories before adding the script.
- [x] After execution approval only, add `tesseract.js@7.0.0` and build-only `@tesseract.js-data/eng@1.0.0`. The latter is justified solely as the same engine's pinned English language asset source, avoiding binary model maintenance in application source or a runtime CDN. It is not another OCR framework/service. Verify the resolved core version with the lockfile/`pnpm why`; preserve all unrelated package changes.
- [x] Extend the reused reader only with owner-opt-in capabilities: structured `blocks`/word evidence tied to each pass, compatible cancellation/deadline signals, explicit `workerPath`/`corePath`/`langPath`, and a 1,800-pixel long-edge cap on the OCR working image. Read certificates in full-image mode with ID-specific crops/passes disabled. Keep original uploads and default customer behavior intact. Do not turn the highest pass confidence into confidence for every word.
- [x] Retain the reader's 60-second operation bound and enforce one 60-second total owner-document budget starting before worker initialization/preparation. Cancellation/deadline ends eligibility immediately; multiple passes cannot multiply that budget. Tests must advance both startup and recognition waits and confirm no automatic retry loop.
- [x] Keep one active worker/document operation. If initialization cannot be canceled or safe termination cannot be confirmed, report unavailable and do not start another worker; terminate a late-created abandoned worker when it becomes available. Do not patch installed dependency source to obtain a hidden worker handle.
- [x] Implement deterministic asset preparation using Node built-in filesystem/path/assert/hash facilities. Resolve package locations through package resolution, including the engine's own resolution context for its core under pnpm; do not hardcode `.pnpm` paths. Copy the published worker, complete compatible core build set and companion WASM, the English `4.0.0_best_int/eng.traineddata.gz`, and required attribution/provenance.
- [x] Emit a small safe manifest at `public/ocr/manifest.json`. It identifies a versioned bundle such as `tesseract-7.0.0-core-7.0.0-eng-1.0.0` using actual resolved versions, relative public worker/core/lang paths, and asset checksums. Do not bake a stale core version into a fixed path or publish application data. Validate expected packages/paths and fail preparation clearly if missing.
- [x] Use these exact relative layouts within that resolved bundle: `worker.min.js`, `core/` containing the published compatible core filenames/WASM, and `lang/eng.traineddata.gz`. Manifest paths are `ocr/<resolved-bundle>/worker.min.js`, `ocr/<resolved-bundle>/core/`, and `ocr/<resolved-bundle>/lang/`; bootstrap resolves them under the deployed application base URL. No URL contains `node_modules`, localhost, or a runtime CDN fallback.
- [x] Add an explicit `prepare:owner-ocr` package command and call preparation from `dev`/`build` without relying on implicit pnpm pre/post hooks. Preserve existing script additions. Keep OCR assets outside `public/build` so Vite's `emptyOutDir` cannot remove them. Ignore generated `public/ocr/` in Git, and include it explicitly in the future release artifact.
- [x] Support `node scripts/prepare-owner-ocr-assets.mjs --check` as a read-only release check; normal preparation must be repeatable. Tests cover missing sources, manifest/path integrity, output hashes, changed versions, and rejection of writes outside the resolved public OCR target. No recursive deletion or production cleanup is required.
- [x] Verify through browser tests later that the only runtime downloads are local engine/core/model assets. Owner options use a same-origin worker (prefer direct worker URL over a blob wrapper when compatible); no automatic external fallback. Do not change customer asset options.

Future dependency/setup and narrow checks:

```powershell
pnpm add --save-exact tesseract.js@7.0.0
pnpm add --save-dev --save-exact @tesseract.js-data/eng@1.0.0
pnpm why tesseract.js-core
node --test scripts/prepare-owner-ocr-assets.test.mjs
pnpm run prepare:owner-ocr
node scripts/prepare-owner-ocr-assets.mjs --check
pnpm run test:frontend resources/js/Pages/UserSide/Auth/registrationOcr.test.ts resources/js/Pages/UserSide/Auth/registrationNameMatch.test.ts
```

Expected: focused tests pass; only intended package/lock changes; manifest/checksums describe installed versions; no runtime API/paid service added. Resolve unexpected package/version behavior before wiring the UI.

**Checkpoint:** Default helper cases stay compatible; owner evidence is pass-local; working images are bounded; packaging/check mode succeeds without network after packages are installed. Production browser asset behavior remains a Phase 7 gate.

**Risks and controls:** Uncommitted package changes -> narrow diffs. Missing/incorrect core variants -> copy the compatible published set, including relaxed SIMD, not an old four-file assumption. pnpm path differences -> package resolution. Missing English/CSP/base-path support -> explicit manifest and browser gate. Decode memory is still not guaranteed by the upload-size cap; skip unsafe OCR rather than weakening upload rules.

## Phase 3 - Implement conservative recognition and the transient browser lifecycle

**Dependencies:** Phases 1-2. **Acceptance:** A1-A4, A6, A7.

**Files:** Create `resources/js/Pages/UserSide/Auth/shopOwnerDocumentRecognition.ts`, `resources/js/Pages/UserSide/Auth/useShopOwnerOcr.ts`, `resources/js/Pages/UserSide/Auth/__tests__/shopOwnerDocumentRecognition.test.ts`, and `resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx`. Extend the two approved test-fixture JSON files and their provenance README.

- [x] Add permitted synthetic image fixtures under `tests/Fixtures/ShopOwnerOcr/images/` and inventory them in the README: DTI, SEC, two visibly distinct permit layouts, Form 2303, supported ID layouts, and a plausible unsupported layout. The fixtures are hand-authored, watermarked, contain invented values only, and use no official marks or real personal information. Actual browser-engine verification remains a Phase 7 gate.
- [x] Write localized-word tests from every approved evidence matrix, including independent issuer/title/structure signals, complete values, field association, and conflicting passes. Missing reliable words/association remain uncertain despite high document confidence.
- [x] Implement DTI and SEC identity/type cases as small pure branches/functions in the owner recognizer. Reuse only compatible ID-family signals from the pinned customer helper source; do not import the customer screening module or its admission policies.
- [x] Add Mayor's Permit cases for varied synthetic layouts and premises addresses; add Form 2303 registered/trade/taxpayer role cases without TIN verification or invented annual expiry. Test clear conflicts and legitimate legal/trade-name uncertainty.
- [x] Add owner-ID name/type/date cases using the existing personal-name helper only as compatible supporting evidence. Partial helper mismatches stay uncertain unless a reliable complete holder field conflicts. No extra side, shop-address comparison, or unsupported-family rejection was introduced.
- [x] Add common date tests using the supplied reference day: dated expiry, future issue, reversed range, metadata conflict, ambiguous date order, known non-expiring versus unknown/unreadable policy, and reference-day rollover while preserving an unrelated conflict. Add `O`/`0`, `I`/`1`, broken/obscured text, missing labels, and partial address cases.
- [x] Apply the approved 2026-10-22 relevance amendment: the owner reader returns transient `document_layout` evidence from a bounded grayscale row-edge sample. Several separated high-contrast row bands preserve unreadable but document-like images as uncertain; a successfully scanned image with no plausible document cues and confirmed non-document-like layout requires replacement. Unknown layout evidence remains nonblocking. This is a coarse layout signal, not a template/object classifier or persisted report field.
- [x] Write deferred-promise hook tests before implementation. Cover latest-file-only queueing, replacement/removal, one active reader, late results, unresolved cleanup, timeout, missing assets, unsupported browser in the reader suite, form edits, and unmount. Reader tests also cover startup timing out and terminating a late-created worker; the hook drains queued work if cleanup is not confirmed.
- [x] Implement the small hook with per-slot selection generations, a sequential queue, transient pass-local evidence, and a submission snapshot serializer. It dynamically imports the reader only for an eligible required file and uses type-only reader imports; the ordinary registration page is not wired until Phase 5.
- [x] Recalculate affected checks from retained words when form/date/type context changes, without rereading. Keep owner-ID context independent of shop address and evaluate asynchronous results against the latest selected type/context.
- [x] On timeout/failure/removal/unmount, invalidate work, clear timers/references, release reader bitmaps/canvases, and abort work. No upload/evidence is stored persistently. Submission snapshots omit unfinished work and are immutable serialized strings; the hook adds no OCR-only request.

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/shopOwnerDocumentRecognition.test.ts
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/shopOwnerScreening.test.ts
```

**Checkpoint:** Met for synthetic recognizer and controlled lifecycle tests. A reliable conflict can yield 0%; missing, weak, unsupported, or contradictory evidence cannot. Relevance remains separate from scoring. The row-layout fallback and blur-vs-photo tradeoff require the Phase 7 actual-browser gate.

**Execution record — Phase 3:** `pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/shopOwnerDocumentRecognition.test.ts resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx resources/js/Pages/UserSide/Auth/registrationOcr.test.ts resources/js/Pages/UserSide/Auth/registrationNameMatch.test.ts` passed (4 files, 55 tests). The fixtures are synthetic SVGs, not actual OCR results; no accuracy claim is made. Owner preprocessing caps large images at 1,800px without upscaling smaller ones. A cleanup timeout deliberately blocks new worker creation and leaves submission available.

**Risks and controls:** Wrong name role -> equivalent-field comparisons and explicit relationship cases. Overconfident page -> local evidence tests. Races/resource buildup -> generations, latest-only queue, cleanup-confirmation fallback. No new parser/queue framework or generic document plugin architecture is needed.

## Phase 4 - Persist validated reports in the existing immutable version flow

**Dependencies:** Phases 0-1; performed after Phase 3 in this sequential run. **Acceptance:** A6, A9, A10, A11.

**Files:**

- Create the nullable-column migration at `database/migrations/2026_10_21_121709_add_ocr_screening_to_shop_documents_table.php`; modify `app/Models/ShopDocument.php`, `app/Services/ShopDocumentLifecycleService.php`, `app/Http/Controllers/ShopOwnerAuthController.php`, and the screening service.
- Create `tests/Feature/ShopDocuments/ShopOwnerOcrPersistenceTest.php`; extend `ShopOwnerAuthRegistrationTest.php` and the lifecycle-schema test to reuse their existing registration setup. Add `bootstrap/app.php` old-input exclusion so malformed or valid screening envelopes are not flashed into the session.

- [x] Write schema and persistence tests first using `RefreshDatabase`, testing SQLite, `Storage::fake('local')`, and existing registration fixtures/fakes. Confirm failure for the missing column/report behavior before implementing.
- [x] Add only nullable JSON `ocr_screening`; no backfill/index/owner cached score/extra approval status. Add the model cast, keep report assignment inside the lifecycle allowlist, and hide the report plus existing checksum/path from default serialization.
- [x] Extend the service to decode/validate optional evidence and construct immutable reports. Normalize before any write. Enforce the byte limit, fixed slots/types/checks/flags/reasons, conditional checks, score derivation, and prohibited fields. Validation messages are fixed and contain no OCR data.
- [x] Store schema version 1, fixed source `browser_tesseract`, server reference day, assessment state, sanitized checks/reasons, server-derived score, server file checksum, and comparison-context hash in the private report. Existing timestamps record the version; no extracted values, verified/authenticated designation, or cached aggregate is stored. Frontend contract tests remain separate from this PHP persistence shape; the admin projection is Phase 6.
- [x] Validate the optional envelope in both `register` and `resubmit` before idempotent responses; preserve all existing validations and explicit pending status. Pass only normalized per-slot evidence through `registrationDocumentEntries`, never mass-assign the request. Laravel excludes `ocr_screening` from validation old-input flashing.
- [x] Extend only the batch entry shape for optional screening. Inside the existing locked version transaction, construct the report from normalized evidence, locked owner context, selected type/date metadata, and the server checksum. Preserve staging, lock ordering, replay checks, transaction boundaries, and cleanup.
- [x] Submission-key replay returns the existing row/report, including null; changed browser evidence cannot update it. Existing identity/bytes/metadata replay conflicts remain unchanged.
- [x] Retained predecessor reports are reused only when checksum, type, relevant context, and schema match. Otherwise the new version remains unassessed; predecessor history and retained-file access stay unchanged.
- [x] Absent-report single-version/renewal/supporting-document calls remain compatible. A forced batch failure rolls back rows and removes staged files.
- [x] A 100% pending report leaves `ShopDocumentValidityService` at `metadata_unverified`; OCR does not fill reviewer metadata or change status/approval.
- [x] Valid registration succeeds with 100%, 50%, 0%, unable-to-assess, or omitted screening; malformed supplied metadata receives an `ocr_screening` validation error. Existing required fields, email verification, date/location policy, and resubmission limits remain enforced.

```powershell
php artisan test tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php tests/Feature/ShopDocuments/ShopOwnerOcrPersistenceTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php --stop-on-failure
php artisan test tests/Feature/ShopDocuments/ShopDocumentLifecycleSchemaTest.php tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php --stop-on-failure
```

Expected: the new column is nullable for legacy rows, reports follow atomic version creation, forged scores/decisions cannot take effect, safe predecessor reuse works, and the existing no-OCR lifecycle suites pass.

**Checkpoint:** Review migration/model/service/controller diff for mass-assignment, private serialization, transaction/replay changes, and existing rule preservation. Do not run the migration against a production/shared database as a test. Production migration belongs to a later approved deployment.

**Risks and controls:** Mutable history -> write once during version creation. Wrong file/context -> server binding and retained-file tests. Pending account promotion -> unchanged explicit status/service authority. Renewal regression -> optional entry/default null and existing lifecycle suites.

**Phase 4 execution record:** The migration was generated by Artisan and is nullable with no index/backfill. The malformed-envelope redirect test first failed because Laravel flashed `ocr_screening` in `_old_input`; `bootstrap/app.php` now excludes that key, and the test passes. A server-reference regression test also caught a missing-day date conflict being upgraded by assigning the server day; the implementation now downgrades missing/stale date passes to uncertain while preserving a reported stale conflict. The persistence suite covers private checksum/context binding, hidden serialization, legacy null rows, replay immutability (including an originally null replay), matching/stale predecessor inheritance, transaction rollback/file cleanup, and pending validity. Registration tests cover accepted scores/unavailable/omitted evidence, malformed metadata, and signed resubmission without changing pending status or existing admission rules.

Final Phase 4 verification: direct `vendor/bin/phpunit tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php tests/Feature/ShopDocuments tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php` passed: 55 tests, 282 assertions, 8 PHPUnit deprecations. `php -l` passed for all 10 changed PHP files; `git diff --check` passed. The two planned `php artisan test` invocations also exited 0, but Laravel's wrapper displayed warnings because this isolated checkout intentionally has no `.env`; the trace points to Dotenv trying to read that absent file. The equivalent direct PHPUnit run is clean apart from existing PHPUnit deprecations. No migration was executed against a shared or production database.

## Phase 5 - Integrate the existing owner form and signed resubmission page

**Dependencies:** Phases 2-4. **Acceptance:** A6-A8, A10, A12.

**Files:** Modify `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx`, `resources/js/Pages/UserSide/Auth/registrationDocumentPayload.ts`, `app/Http/Controllers/UserSide/LandingPageController.php`, `app/Http/Controllers/ShopOwnerAuthController.php`, and the new screening service. Create `resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOcr.test.tsx` and `tests/Feature/ShopDocuments/ShopOwnerOcrBootstrapTest.php`; extend `resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx`.

- [x] Activate the Design role before component changes: apply `ui-ux-pro-max`, `design-system`, and `ui-styling` to reuse existing drop zones, labels, spacing, and accessible status patterns. No media work or unrelated page redesign is included.
- [x] Add a safe service bootstrap method that reads/validates `public/ocr/manifest.json` if present and builds same-origin application URLs from its allowlisted relative paths, plus server reference day/shop timezone. Do not blindly use a possibly cross-origin asset-CDN URL or accept paths from the applicant. Missing/invalid manifest returns unavailable OCR configuration, never a registration-page error.
- [x] Supply that same optional configuration from `LandingPageController::shopOwnerRegister` and `showResubmissionForm`. Leave customer `register`, shared global Inertia props, auth routes, signed URL checks, and existing resubmission response data intact.
- [x] Write page/bootstrap/payload tests for fresh registration and signed resubmission, absent bootstrap/assets, original-file submission, unfinished scan, safe optional omission, and unchanged CSRF/endpoint choice. Mock only OCR/geolocation/network/modal surfaces needed for focused page behavior, not validation away.
- [x] Wire required-file selection/replacement/removal and relevant form/date/type changes into the hook. Show compact aria-live progress and preliminary/manual-review/unavailable statuses next to existing fields. Do not show detailed thresholds or add mandatory applicant acknowledgement fields.
- [x] Extend the payload helper compatibly to append only valid serialized optional evidence. Existing callers with no evidence produce the same multipart files/metadata/submission keys. File field names, accepted formats/size, optional documents, and original image bytes are unchanged.
- [x] Keep submit independent of OCR scores and report serialization, but pause it while a newly selected required file is queued/scanning. Require replacement only for a successfully classified irrelevant image or reliably incompatible type; 0% field conflicts and plausible 50% uncertainty remain submitable. Disable the final button and repeat the guard in its handler. Technical failure or safe serializer omission leaves existing validation/error navigation operational.
- [x] On failed ordinary validation, keep evidence only for unchanged file/context. On completed submission/unmount, release transient evidence/work. Retained signed-resubmission documents without local OCR text remain eligible for manual review and rely only on safe server-side report inheritance.

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/useShopOwnerOcr.test.tsx
php artisan test tests/Feature/ShopDocuments/ShopOwnerOcrBootstrapTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationOcrTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php --stop-on-failure
```

**Checkpoint:** Same optional bootstrap on both pages; no early Tesseract import; existing multipart payload stays compatible; submission succeeds independently of OCR when existing mandatory requirements are met.

**Risks and controls:** Fresh/resubmission drift -> shared bootstrap and paired tests. Asset missing -> unavailable fallback. Implicit extra registration gate -> submit-while-pending and serializer-failure tests. Duplicate preprocessing/upload system -> original form/files retained.

**Phase 5 execution record:** The shared bootstrap validates the versioned manifest and confirms every declared asset remains in the local public bundle. It returns only root-relative same-origin paths, preserving configured base paths and trailing directory slashes required by Tesseract; missing or corrupt assets produce `available=false`. Both registration entry points supply the same optional props. The form starts the existing sequential hook only for required file selection, clears evidence and preview URLs on replacement/removal, recalculates retained text against current form/metadata/type context, snapshots only completed optional evidence, and finishes the hook on successful submission. OCR status is text-based and live-announced; it does not show applicant scores or delay submit. The shared drop zone's pre-existing `Files dropped` console log was removed because it logged `File` objects on this sensitive flow.

Final Phase 5 verification: `pnpm run test:frontend` over the registration page/payload, owner hook, recognizer, scoring, and reused customer reader/name suites passed: 93 tests across 7 files. This includes the 9-test page integration suite covering server bootstrap forwarding and selected-file removal. `vendor/bin/phpunit tests/Feature/ShopDocuments/ShopOwnerOcrBootstrapTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php` passed: 23 tests, 180 assertions, 8 existing PHPUnit deprecations. `php -l` passed for the four changed PHP files and `git diff --check` passed. Frontend runs emitted the Node `--localstorage-file` warning configured by the host test environment. Fresh/resubmission HTTP paths, missing/corrupt assets, configured base path, multipart original file, hook cleanup/snapshot behavior, and applicant status copy have focused coverage; full production browser asset and CSP checks remain Phase 7.

## Phase 6 - Add preliminary results to existing Super Admin review

**Dependencies:** Phases 1, 4-5. **Acceptance:** A5, A10, A11, A12.

**Files:** Modify `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`, `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`, and the new screening service. Create `tests/Feature/SuperAdmin/ShopOwnerOcrReviewTest.php`, `tests/Feature/SuperAdmin/ShopOwnerOcrDecisionIsolationTest.php`, and `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationOcr.test.tsx`. Extend existing view tests only as needed for optional props.

- [x] Write backend projection/summary tests first: legacy null reports, numeric/nonnumeric states, aggregate examples, pending candidate selection, historical isolation, context changes, and reference-day snapshots. Explicit eager-loaded inputs avoid N+1 file reads.
- [x] Add only the report/checksum/current-version columns needed by the explicit selection. Inertia receives allowlisted nested OCR results; hashes, raw report internals, and private paths remain excluded.
- [x] Derive summary from the four pending non-current candidates; exclude supporting/predecessor history. Completed applications show their latest reviewed set as historical. Null, unavailable, or stale results never become 0% by default.
- [x] Detect stale server context and draft authority/date corrections without persisting reviewer edits. Preserve dated snapshots alongside current validity labels, including `metadata_unverified`.
- [x] Add preliminary score/state, fixed check/reason text, reference day, and separately labelled recognition confidence with the approved disclosure. Text labels carry meaning independently of color.
- [x] Keep viewed-document checks, reviewer metadata, the explicit decision payload, and approve/reject controls authoritative. The OCR display does not mark viewed or verify metadata; original viewer routes and historical review requirements remain intact.
- [x] Add explicit authorized approve and reject cases at 0%, 50%, 100%, unable-to-assess, and unassessed states. Verify viewed/reviewer metadata and rejection-reason requirements; existing authorization tests continue to cover protected access.
- [x] Leave `ShopOwnerRegistrationDecisionService`, protected viewer authorization, audit/mail/provisioning, and operational-access middleware unchanged.

```powershell
pnpm run test:frontend resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
php artisan test tests/Feature/SuperAdmin/ShopOwnerOcrReviewTest.php tests/Feature/SuperAdmin/ShopOwnerOcrDecisionIsolationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php --stop-on-failure
```

**Checkpoint:** Every score remains advisory, originals stay protected, current/history aggregation is correct, and both decisions still require their existing workflow. Approving a report is not a new action or status.

**Risks and controls:** Average hides conflict -> fixed minimum/conflict precedence. Stale high score -> context/schema checks. Reviewer anchoring -> prominent source/disclosure/reasons/original viewer. Accidental authority bypass -> decision-isolation tests and unchanged production decision service.

**Phase 6 execution record:** `pnpm run test:frontend --reporter=dot resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts` passed (2 files, 11 tests). `php vendor/bin/phpunit tests/Feature/SuperAdmin/ShopOwnerOcrReviewTest.php tests/Feature/SuperAdmin/ShopOwnerOcrDecisionIsolationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php` passed (46 tests, 389 assertions). `php -l` passed for both changed PHP files and `git diff --check` passed. The test environment emits its existing Node `--localstorage-file` warning. OCR projection is allowlisted; the admin UI also displays existing server validity state and dated screening separately. No decision service or protected viewer implementation changed.

## Phase 7 - Verify all acceptance groups, build, inspect in browser, and hand off

**Dependencies:** Phases 0-6; isolated test environment, permitted fixtures, browser access, and disposable authenticated test actors for protected review flows. **Acceptance:** A1-A12.

**Files:** Complete focused tests/fixtures and `docs/operations/shop-owner-registration-ocr.md`; update only this feature's execution/evidence record. `docs/ai-learning-log.md` receives a concise durable lesson only if one emerges and its existing unrelated content is preserved.

- [x] Run the narrow new tests first, then the existing affected suites below. Compare failures to Phase 0; do not silently absorb unrelated fixes. Retain customer helper tests/defaults, and run full customer identity suites only if that feature actually exists in the execution branch. Do not merge it to manufacture test coverage.
- [x] Run broader frontend/backend suites where practical after the narrow gates pass, then the production build and asset integrity check. Record exact commands/results. A successful Vite build does not constitute TypeScript compiler/lint validation or browser OCR accuracy evidence.
- [x] Inspect the built import graph/network: opening registration without a document must not fetch the OCR worker/core/language or execute Tesseract; selecting a required file should load the deferred reader/runtime. Do not introduce a manual vendor chunk, new lazy wrapper, or broad bundle refactor unless necessary to correct an observed eager import.
- [x] Perform browser verification with `webapp-testing`/available Playwright or browser controls against a disposable local environment, not production. Preserve normal email/location/auth validation. Use existing test setup/accounts/local mail capture and a supported local Cavite-location test arrangement, without adding auth bypass routes or putting credentials in code/artifacts. If the required test actors/environment are unavailable, record that portion as blocked/not run rather than fabricating a pass.
- [x] Exercise actual browser OCR on synthetic fixtures in Chromium and WebKit, inspect cold-cache lazy loading, and force a missing local worker to confirm failure cleanup without external fallback. Detailed results are in the execution record.
- [x] After the approved relevance amendment, run the actual Chromium Tesseract engine over synthetic blank/solid images, a meme, landscape, selfie-like drawing, wrong SEC and PhilSys families, an unsupported certificate, a heavily blurred document-like certificate, a DTI field conflict, and a missing-worker failure. Verify relevance outcomes, local-only asset requests, and the 0% conflict remains nonblocking. Synthetic outcomes are functional evidence only; no accuracy rate is claimed.
- [ ] Complete the full authenticated fresh-registration and signed-resubmission browser journeys, including replacement/removal, relevant form changes, pending scan submission, accessible state, and normal email/location validation. The local OTP path was unavailable; focused tests cover these flows.
- [ ] Verify the protected Super Admin page in a browser with test registrations spanning scores/unknown states, historical versions, original-document viewing, and explicit decisions. No authenticated test reviewer was available; backend decision-isolation tests passed.
- [x] Capture local worker/core/language URLs, statuses, MIME types, cold-cache requests, and a smoke archive containing both `public/build/` and ignored `public/ocr/`.
- [ ] Verify target-host subpath routing and actual CSP worker/WASM compatibility. The backend has a `/portal` path test; local browser ran at root and no CSP header was available.
- [x] Test a missing local worker produces an OCR failure without external requests. A separate language-path 404 browser attempt is inconclusive because harness cleanup timed out; unit tests cover missing/unavailable asset behavior. Do not claim real-engine accuracy, fraud detection, performance, or peak-memory improvements.
- [x] Complete the applicable review stack in order, then reuse/dead-code checks. Revisit an earlier gate only if a later change invalidated its evidence. Update the operations note with local asset build/shipping, preliminary labels, manual-review fallback, actual results, and remaining measurement limits.

Future regression/quality commands (new test commands remain listed in their owning phases):

```powershell
pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/UserSide/Auth/__tests__/RegistrationDocumentMetadataFields.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
php artisan test tests/Feature/ShopDocuments tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php --stop-on-failure
pnpm run test:frontend
composer test
node --test scripts/prepare-owner-ocr-assets.test.mjs
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
git diff --check
```

For a preconfigured disposable local verification app, start only its server when needed:

```powershell
php artisan serve --host=127.0.0.1 --port=8001
```

Verify the selected app/database isolation and port availability before using that command. It does not initialize a database or approve access to production. The current uncommitted `scripts/browser-smoke.mjs`/`pnpm run test:browser` may be used only if present and authorized in the execution baseline; its generic smoke result does not cover the OCR acceptance scenarios. No additional browser framework is required just to execute the manual/available-tool checks.

**Checkpoint:** Every acceptance row below has concrete test/browser evidence or an explicit unresolved/not-run status. Required failed/missing gates prevent an implementation-complete claim. Report accuracy, fraud-detection performance, speed, and device memory as **not measured** unless separately measured with suitable permissioned data.

**Risks and controls:** Wrong database/browser account -> isolate before use. Missing asset/core/browser coverage -> explicit recorded limit and no deployment-ready claim. Tracked generated-build changes -> perform build in isolated checkout and stage only the intended feature artifacts; never overwrite the root's existing build work.

## Acceptance-to-verification matrix

| Spec group | Owning phases | Required evidence location |
| --- | --- | --- |
| A1 Recognition | 3, 7 | `shopOwnerDocumentRecognition.test.ts`, fixture inventory with two LGUs/multiple IDs, real-engine permitted image checks. |
| A2 Roles/matching | 3, 7 | Recognizer tests for legal/trade linkage, proprietor/representative/signatory, names/addresses, ID address independence. |
| A3 Recognition errors | 1-3 | Reader/recognizer cases for local versus document confidence, medium/low/absent words, character ambiguity and obscured fields. |
| A4 Dates | 1, 3-4, 6 | Date cases, supplied reference day, non-expiry policy, existing mandatory date metadata and unverified pending status. |
| A5 Scoring | 1, 6 | Shared TS/PHP cases and admin tests for all scores/states/five aggregates, conflict precedence and incomplete indicator. |
| A6 Relevance gate and technical fallback | 3-5, 7 | Hook/page tests cover pending/handler guard, blank/random/meme/wrong type replacement, plausible unsupported and unreadable document-like allowance, 0% conflict allowance, and technical failure. Actual Chromium Tesseract run covers the same relevance boundary on synthetic images; malformed metadata stays a separate Laravel validation case. |
| A7 Browser lifecycle | 2-3, 5, 7 | Reader/hook deferred-promise/fake-timer tests, worker cleanup/fallback/image bound, browser replacement/unmount/slow-scan checks. |
| A8 Deployment | 2, 5, 7 | Node packaging tests, actual versioned manifest/checksums, production build, cold-cache same-origin resource/core/base-path checks. |
| A9 Persistence | 1, 4 | Envelope boundary/forbidden-field cases, model/schema checks, private serialization, server binding, replay and rollback tests. |
| A10 Version isolation | 4-6 | Retained/replaced signed resubmission and reapplication, context/schema changes, corrected metadata, history/current aggregate tests, dated snapshot warnings. |
| A11 Authority/privacy | 4, 6-7 | Per-score approve and reject cases, missing-view/metadata/reason denials, viewer authorization/ownership, pending operational-access denial, safe props/logging. |
| A12 Regressions | 0, 2, 4-7 | Recorded baseline, compatible helper defaults, existing registration/location/email/document/decision/privacy suites, full frontend suite (309 files/2,011 tests after amendment), customer tests actually present, unrelated-work diff audit. The full Composer suite remains incomplete. |

## Review, commits, deployment boundary, and rollback

After each phase's narrow checks pass, inspect its diff and stage only the exact owned paths for that phase. Run `git diff --cached --check` before a small coherent commit; never use broad staging, push, merge, or deploy automatically. Unrelated package changes and root artifacts remain outside those commits. A completed phase is not a production release.

Required implementation review order:

1. Simplify with `ponytail`: remove duplicated recognition/scoring work, unnecessary wrappers/options, or unused code introduced by this feature.
2. Sequential repository Standards, approved Spec/A1-A12, then correctness risks. No parallel reviewer/installed parallel code-review workflow without explicit user approval.
3. Changed TS/TSX quality using `typescript-advanced-types` and `vercel-react-best-practices` as applicable: simple typed boundaries, safe narrowing, small focused responsibilities, no unnecessary assertions/any.
4. `karpathy-guidelines`: explicit assumptions, minimum coherent diff, existing framework/service reuse, and verifiable criteria.
5. Relevant code-splitting/import review: prove OCR is deferred; do not split small UI or add chunks without evidence.
6. Gauge improvements: use baseline/after evidence for functional claims; performance/accuracy claims remain not measured unless actually evaluated.
7. `security-review` with relevant `laravel-best-practices` validation/security/model/migration rules: untrusted input, private storage/serialization, mass assignment, original viewer rights, replay/rollback, and manual decision authority.
8. `superpowers:verification-before-completion`: fresh concrete commands/browser evidence before completion claims.

Then confirm reuse, inspect changed areas for unused imports/unreachable branches/stale references/abandoned TODOs, write operations/evidence notes, and record only durable lessons. Do not delete unfamiliar existing code or change unrelated standards/tooling.

Deployment is not authorized by planning or routine phase execution. A later approved release must ship the matching frontend/backend plus generated `public/ocr/` and apply the one additive nullable-column migration before new code queries it. The release artifact must include the generated OCR directory even though it is ignored by Git. Node is build tooling only, not a production OCR service. Verify local asset delivery and normal admin review after release.

Rollback preference: revert the feature application/frontend release while retaining the additive nullable column and private report data. Do not drop reports or revert database schema destructively as an automatic rollback. No changes to original files, historical documents, approval records, or customer verification are needed to stop using OCR. Obtain explicit approval for any production data-destructive operation.

## Plan review and approval checkpoint

Sequential plan review completed in this order: repository Standards, approved Spec/A1-A12 coverage, then correctness/security/compatibility risks. Resolved findings made the total deadline, local asset layout, clean-worktree dependency setup, non-expiring normalization, and wrong-type-versus-malformed distinction explicit. There are no unresolved plan-blocking findings; implementation tests and benchmarks have not run during planning.

| Planning review gate | Result |
| --- | --- |
| Simplify | Pass: minimal customer source port, one report column/service, existing form/viewer/decision flow, no new workflow or OCR infrastructure. |
| Standards, Spec, risks | Pass for the plan: file-level phases, dependencies, narrow commands/checkpoints, all A1-A12 mapped, unrelated work and manual decision authority protected. |
| Changed TypeScript quality | N/A: no TS/TSX authored; the implementation gate is required above. |
| Karpathy guidelines | Pass for planning: facts and execution dependencies are distinguished; changes stay within the approved specification. |
| Code splitting | N/A for this document; lazy import and production network verification are explicit execution gates. |
| Gauge improvements | Not measured; no empirical accuracy, resource, or speed claim. |
| Security review | Pass at planning level: untrusted browser boundary, bounded private reports, immutable versions, original viewer rights, and unchanged approval/rejection guards have tests planned. |
| Verification before completion | Documentation-only checks cover references, acceptance mapping, Markdown/whitespace hygiene, spec identity, and task scope. Application and browser checks are deferred, not claimed. |

Reuse audit: the pinned reader/name logic, existing upload payload/lifecycle, protected viewer, and decision service are reused. Dead-code scan: N/A because no application code is authored or removed. Writing: this separate plan records the execution contract and checkpoints. Vault learning: no unrelated learning-log edit is needed; task-specific findings remain here.

Planning verification performed:

- `git diff --no-index --check -- /dev/null docs/superpowers/plans/2026-10-21-shop-owner-registration-ocr.md`: no whitespace findings; exit 1 denotes the new-file difference.
- `git diff --check -- docs/superpowers/specs/2026-10-21-shop-owner-registration-ocr-design.md`: pass.
- `Get-FileHash -LiteralPath docs/superpowers/specs/2026-10-21-shop-owner-registration-ocr-design.md -Algorithm SHA256`: matches the approved hash above.
- Read-only PowerShell structure/reference assertions: eight ordered phases with dependencies/commands/checkpoints/risks, twelve acceptance rows, eleven existing modification targets, balanced code fences, valid relative spec link, and no unresolved placeholders. Existing regression paths were checked separately; planned new paths are deliberately absent.
- `git cat-file -e` against the six pinned customer helper/test paths: all present. `git diff --cached --name-only`: empty; HEAD/branch unchanged and the plan remains untracked for review.
- No application tests, dependency installation, migration execution, asset generation, production build, browser flow, or OCR benchmark ran during planning.

This plan deliberately uses inline sequential execution rather than the writing-plans skill's generic subagent review/handoff options, because `AGENTS.md` requires explicit authorization before delegation. It also defers worktree creation, dependency installation, migrations, code/assets, app tests, builds, and browser execution to the separately approved execution stage.

No further architectural decision is requested. Execution dependencies to verify at their phase gates are the approved baseline/spec snapshot, compatible reader evidence/cancellation behavior, pinned packages and local asset manifest, permissioned fixtures, and an isolated test/browser environment. Missing dependencies remain visible; they do not justify switching OCR architecture or weakening registration/review requirements.

The original planning approval checkpoint was satisfied by the user's subsequent explicit authorization to execute Phases 0-7. The resulting implementation and Phase 7 verification status are recorded below.

### Upstream references used for the planning details

- [Tesseract.js v7 worker recognition interface](https://github.com/naptha/tesseract.js/blob/v7.0.0/src/createWorker.js): structured output is opt-in; retain default customer calls and use owner-specific options.
- [Tesseract.js v7 browser core selection](https://github.com/naptha/tesseract.js/blob/v7.0.0/src/worker-script/browser/getCore.js): package compatible baseline, SIMD and relaxed-SIMD variants.
- [Official language-data distribution](https://github.com/naptha/tessdata/blob/gh-pages/README.md) and [English package versions](https://www.npmjs.com/package/%40tesseract.js-data/eng?activeTab=versions): pinned English data may be served locally with no runtime CDN or inference service.

## Execution outcome - 2026-10-21

Phases 0-6 were implemented sequentially in the approved isolated worktree. Their focused suites passed; per-phase changes and counts are in the [execution record](../../operations/shop-owner-registration-ocr-execution.md). Phase 7 passed the production Vite build, asset integrity/package checks, local asset response checks, direct real-engine Chromium/WebKit reads on synthetic fixtures, and the affected backend regression suite. The generated Vite output was restored from HEAD after build inspection; generated ignored `public/ocr/` remains in the worktree for a future package review.

Phase 7 is **incomplete**. The authenticated registration/resubmission and Super Admin browser journeys were not completed because the disposable local OTP flow could not deliver the test code to browser automation and no authenticated reviewer session was available. Full `pnpm run test:frontend` has one failure in contradictory, unrelated Repairs navigation tests. `composer test` did not finish within its 300-second process limit and its PHPUnit child hit the 512 MB memory limit, with failures reported in unchanged ERP, logistics, suspension, maintenance, manager, notification, and repair suites. These are not established as pre-existing because a complete baseline suite was unavailable. Hostinger target delivery, production CSP, browser subpath hosting, and language-path 404 behavior remain unverified or inconclusive.

| Acceptance group | Current status |
| --- | --- |
| A1-A5 | Focused synthetic recognition, field matching/date cases, TS/PHP scoring parity, and admin projection tests pass; actual OCR was exercised on eight synthetic images in Chromium and one in WebKit. Accuracy is not measured. |
| A6-A7 | Focused and full frontend tests pass after the relevance amendment; synthetic Chromium/Tesseract cases exercise the gate. Authenticated OTP journeys and full integrated page lifecycle remain unverified. |
| A8 | Build, manifest/checksum, local HTTP/MIME, lazy-load, and archive smoke checks pass. Hostinger, CSP, and browser subpath deployment remain unverified. |
| A9-A10 | Persistence, privacy, replay, rollback, retained-file, stale-context, and version-isolation tests pass. No production migration was run. |
| A11 | Backend decision/view authorization tests pass at the scores and unassessed state. Authenticated Super Admin browser verification remains unrun. |
| A12 | Full frontend suite passes on the amended worktree (309 files/2,011 tests); the focused backend suite passed after rebase. The full Composer suite remains incomplete as described above. |

Do not describe this worktree as fully verified or deployment-ready until the remaining Phase 7 gates are resolved or accepted in a later release decision. Deployment, push, merge, and production migration remain unauthorized.

## Historical applicant-relevance gate verification addendum - 2026-10-22

The user-approved relevance amendment is implemented in the isolated `solespace-b` worktree. The final wizard pauses while a required scan is queued/running and blocks only successfully classified clearly irrelevant or incompatible required images. The guard is repeated in the submit handler. Plausible documents remain submitable when checks are uncertain or business/owner/address/date fields conflict, including score 0%; technical OCR failures remain nonblocking. No backend report, decision, authorization, or customer OCR code changed for this amendment.

The transient classifier uses local Tesseract pass evidence plus a bounded grayscale row-edge layout signal. It does not use templates, logos, colors, image similarity, faces, or external services. A document-like unreadable image remains uncertain. This heuristic can miss unusual document layouts and can treat structured photos as document-like; the client-only block can be bypassed by manipulating or skipping browser code. It deters obvious troll uploads but does not establish authenticity or enforce a server-side security boundary.

Fresh verification for the amendment:

- Focused Vitest direct-binary run: five files, 80 tests passed.
- Full frontend direct-binary run: 309 files, 2,011 tests passed. This supersedes the prior full-frontend results in the historical Phase 7 record.
- Node asset packaging tests: four passed; pinned `public/ocr` manifest/assets passed read-only integrity verification.
- Project production build script passed with 3,850 modules transformed and output directed to a new temporary directory, preserving tracked `public/build`. The generated manifest keeps `registrationOcr.ts` dynamically imported.
- Actual Chromium with local Tesseract 7 exercised synthetic blank/solid images, meme and random images, selfie-like drawing, wrong SEC/ID families, unsupported government-like text, unreadable document-like layout, a plausible DTI field conflict, and forced worker failure. Outcomes matched the stated gate; no OCR text was retained and no external OCR requests occurred. These checks are functional examples, not accuracy or fraud-performance evidence.
- `git diff --check` passed. The asset checker needed authorized package-store read access because default sandbox access could not traverse the pnpm store; the successful check made no changes.

The latest amendment does not close Phase 7: authenticated fresh registration/resubmission/admin browser journeys, production Hostinger delivery/CSP/subpath behavior, and the full Composer suite remain unverified or incomplete as detailed in the [execution record](../../operations/shop-owner-registration-ocr-execution.md). No push, merge, deployment, or production migration occurred.

## Current relevance correction - 2026-10-22

The historical row-edge heuristic above is superseded by the user's subsequent bug-fix request. Actual-form reproduction showed that a synthetic portrait could be called document-like and fall through to allowed uncertainty. A successfully completed scan now requires positive issuer/heading/field-structure evidence to remain eligible. No usable document evidence, including an empty successful read, requires replacement/retake; it is not a technical failure. Reliable incompatible families require replacement. Plausible supported/unsupported documents remain eligible with their existing categorical matching score; real worker/asset/browser failures remain nonblocking.

The implementation removes the unused pixel-layout sampler, preserves technical-failure state, adds a final relevance guard after asynchronous confirmation/CSRF retrieval, and clarifies recognized-type versus uncertain-field feedback. Existing file/preview state is reused, full-document previews and safe bounded filename display are verified, and original multipart files remain unchanged. The administrative score rules, Laravel validation/persistence, signed resubmission and manual decisions are not replaced.

Execution follows the scoped file-level plan and fresh verification in the [correction report](../../operations/2026-10-22-shop-owner-ocr-relevance-fix.md). Its recorded results supersede only the corresponding earlier assertions. Original Phase 7 deployment/authenticated-review/full-suite limitations remain visible rather than being declared complete by this correction.

## User-authorized merge handoff - 2026-10-22

The user separately authorized committing, rebasing onto the fetched `origin/solespace-b`, refreshing `public/build/`, and pushing `codex/shop-owner-registration-ocr` without merging or deploying. For this Git-based Hostinger handoff, commit the matching generated `public/ocr/` runtime with the production build; this supersedes the earlier generated-output ignore policy. Do not rely on an ignored archive reaching the server. Preserve the original workspace and local artifacts, run the frozen pnpm install and affected regressions, and verify that committed runtime bytes match their manifest. The [merge-preparation record](../../operations/2026-10-22-shop-owner-ocr-merge-readiness.md) records fresh results and remaining release prerequisites. Production migration/deployment and the final merge remain unauthorized.
