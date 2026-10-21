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
