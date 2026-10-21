# Shop-owner registration OCR execution record

Date: 2026-10-21

Execution follows the approved October 21 specification and implementation plan. OCR remains browser-only and untrusted. Super Admin approval/rejection is still the sole decision path. No deployment, push, merge, or production migration is authorized.

## Phase 0 - Isolated baseline

- Branch/worktree: `codex/shop-owner-registration-ocr`, `.worktrees/shop-owner-registration-ocr`.
- Approved design copied from the original workspace and verified at SHA-256 `AFF1C92F87DF8A54A80C49C91A8CF35DBBF447B548A3A6844AC6ADE6172E6F27`.
- Original workspace inventory (592 modified/untracked paths and SHA-256 values) is stored in `output/shop-owner-ocr/original-workspace-baseline.json` in this ignored worktree output area. No root user edits were copied or rewritten.
- A fresh original-workspace comparison found the same 592 paths, zero path/status differences, zero file hash drift, zero staged paths, and the same branch/HEAD/spec hash as the baseline.
- Clean checkout initially lacked `vendor/` and `node_modules/`. Locked dependencies were installed in the worktree with `composer install --prefer-dist --no-interaction --no-scripts` and pnpm 11.14.0 `install --frozen-lockfile`. Composer's optimized autoload generation completed; Composer's application lifecycle scripts did not run. The first Composer attempt was interrupted during slow optimized classmap generation; `composer dump-autoload --no-scripts --no-interaction` then completed successfully.
- Test database isolation is confirmed by `phpunit.xml`: APP_ENV testing, SQLite `:memory:`, array cache/session/mail, and synchronous queue. Test commands explicitly set the SQLite testing environment. No `.env` was read or changed.
- Focused frontend baseline: `pnpm run test:frontend resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts` — pass, 2 files, 8 tests. Node emitted an environment warning that `--localstorage-file` had no valid path.
- Laravel baseline: `php artisan test tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php --stop-on-failure` — exit 0, 32 tests, 220 assertions, 32 PHPUnit warnings. PHPUnit's direct `ShopDocumentInvariantTest.php --display-warnings` rerun passed 4 tests/13 assertions without those warnings. The warnings are retained as baseline evidence; no production validation was changed to suppress them.
- Pinned customer helper/test paths were verified in Git object `6540002bdfb0fd63d5f7f643f225343facc8ac77`; the approved helper dependency versions remain reference facts only until the package phase.
- No application code, migration, OCR asset, or dependency change is made in Phase 0.

## Later phase evidence

Phase-by-phase changes, focused checks, acceptance coverage, browser asset requests, and limitations are recorded below as each phase completes. Do not mark a phase complete without its listed checkpoint evidence.
