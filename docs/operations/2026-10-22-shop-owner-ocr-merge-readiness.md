# Shop-owner OCR merge preparation - October 22, 2026

Status: locally verified for manual merge review. No deployment or production migration authorized or performed.

User-authorized sequence: preserve local work, commit only OCR changes, rebase onto freshly fetched `origin/solespace-b`, run affected tests, install the frozen pnpm lockfile, generate and commit fresh production assets, verify the runtime inventory, and push the feature branch without merging or force-pushing.

The worktree initially used `codex/shop-owner-registration-ocr-solspace-b` at `005f1d9cebbdba64cd55ca453c0c1e84ba2477ea`. The requested feature name still pointed to the older `59cc4f20c183038fd3a114e9f8136dfa7222fc9c`; it was preserved under `backup/shop-owner-registration-ocr-before-finalize-20261022`, and the current worktree branch was renamed to `codex/shop-owner-registration-ocr`. Both feature remote names were absent. Fetched target: `e0d8f18143eaeecb7e4e3b9d264ca2f24020104a`.

Preservation inventory: `output/ocr-merge-prep/preservation-before.json` records hashes for the dirty worktree and original workspace; status/index snapshots are beside it. Output artifacts, generated Python bytecode and unrelated original-workspace changes are excluded from staging. Existing files are not cleaned or reset.

The approved browser-only architecture and current document-relevance policy remain authoritative. The recognition fixes, owner/result Swals, private immutable reports and Super Admin projection are included; the decision service remains the only approval/rejection authority. Current operational limits are in the [correction report](2026-10-22-shop-owner-ocr-relevance-fix.md).

Runtime delivery decision: commit the generated `public/ocr/` alongside the fresh `public/build/`, including worker, compatible core/WASM variants, English data, licenses/notices and checksum manifest. Git-based Hostinger deployment must receive these tracked files; no ignored local archive is required. Actual Hostinger delivery/CSP/base-path checks and the additive migration remain separate release prerequisites.

Browser-runner maintenance accounts for the loading/result Swal: pending submission is recorded as blocked by the overlay without claiming the submit handler ran; focused tests independently verify that guard. Active removal invokes the real registered callback behind the overlay without injecting state. Normal completed cases still exercise the form, actual engine and final handler transport capture.

## Rebase and commits

`git rebase origin/solespace-b` completed with **already up to date**, no conflicts and no rewriting needed in this handoff. A second fetch before building confirmed the same target `e0d8f18143eaeecb7e4e3b9d264ca2f24020104a`; the ancestor check passed. No stash, reset, clean, force-push or merge was used.

- `59c685183d69e059ec77cbba13ae0dbeef8bfe2c`: recognition/relevance corrections, form feedback, fixtures and operational records.
- `9b795f1ad4`: current specification and browser accessibility checks.
- `43abd1c63e6026c2ec94994e196205f38392a374`: fresh production build and complete tracked OCR runtime.
- This final verification report is committed separately. Git supplies the final head at push time.

## Fresh verification

These commands ran after the rebase check. pnpm used cached CLI 11.14.0 through `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...`; no package/lockfile versions changed.

```powershell
pnpm install --frozen-lockfile
pnpm run test:frontend resources/js/Pages/UserSide/Auth resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationOcr.test.tsx resources/js/components/form/form-elements/__tests__/DropZone.test.tsx
php artisan test tests/Unit/Services/ShopOwnerDocumentScreeningServiceTest.php tests/Feature/ShopDocuments tests/Feature/LocationPolicy tests/Feature/SuperAdmin/ShopOwnerOcrReviewTest.php tests/Feature/SuperAdmin/ShopOwnerOcrDecisionIsolationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php --stop-on-failure
node --test scripts/prepare-owner-ocr-assets.test.mjs
python -B tests/Browser/shop_owner_ocr_relevance.py --mode verify --output output/ocr-merge-prep/browser-final
python -B output/ocr-merge-real/verify_specimens.py --folder 'C:\Users\slyha\Downloads\asd'
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
git diff --check
git diff --cached --check
```

| Check | Actual result |
| --- | --- |
| Frozen pnpm install | Passed, already up to date. |
| Frontend owner/customer/auth/OCR/admin/upload suites | **23 files / 277 tests passed**, exit 0. |
| Laravel OCR/document/location/OTP/decision/private-access suites | **146 tests / 895 assertions passed**, exit 0, testing SQLite. |
| Asset packager | **4 tests passed**, exit 0. |
| Synthetic actual-engine form matrix | **20 scenarios passed**, exit 0; no runtime errors, external OCR requests or HMR reloads. |
| Supplied originals and wrong-slot controls | All **7 observations** met expected outcomes; five correct families/eligible originals and two incompatible-family blocks. Original hashes unchanged. |
| Fresh default production build | Passed in **31.62 seconds**, exit 0, output actually generated in `public/build/`. |
| Build manifest / lazy loading | **401 entries**, all declared files present; 40 static owner chunks exclude the dynamic reader. |
| Runtime integrity | All **24 OCR files tracked**, exact upstream checksum-covered bytes; six valid WASM variants and valid compressed English model. |
| Git-created public archive | **427 files**, every archived hash matches the build; all OCR manifest paths/files included. |
| Whitespace and staging | Passed; only owned OCR changes and matching generated assets committed. |

The pending browser scenario records `not_reached_loading_dialog`: the submit button is inaccessible behind the active modal, so this case does not claim the callback ran. Focused frontend tests independently exercise its guard. Other blocked cases invoke the actual submit handler; allowed cases reach captured final Inertia transport with original file hashes intact. Normal OTP/location validation uses disposable SQLite and loopback SMTP. No browser registration or reviewer notification is written.

The first matrix attempt stopped because the removal control was hidden from accessibility selectors by SweetAlert. The passing runner explicitly includes that control before invoking its real callback; it does not inject component state or weaken production guards. The original JPGs had been renamed, so their labels were bound to the prior SHA-256 inventory. Initial original-image runner attempts stopped on old filename prefixes and metadata interaction under the loading modal, before original-image recognition; those are not passes. The final run fixes runner ordering only. DTI/SEC/Cainta/Quezon City/BIR remain eligible at scores 50/50/0/0/50 in the synthetic comparison context; SEC-in-DTI and DTI-in-permit are incompatible-family 0% blocks.

Evidence stays local under `output/ocr-merge-prep/` and `output/ocr-merge-real/`. `public-assets.zip` was created from committed Git objects at `43abd1c63e6026c2ec94994e196205f38392a374`. It is an asset verification archive, not a deployed or complete Laravel release.

## Runtime delivery and preservation

`public/ocr/` is no longer ignored: its worker, core JavaScript/WASM variants, English model, notices/licenses and manifest are committed with `public/build/`. Git-based Hostinger checkout therefore receives actual runtime files, not an omitted ignored archive or LFS pointer. Staged Git blobs and the final Git archive match every runtime SHA-256. The upstream core license has a checksum-covered extra EOF newline; the license-only `.gitattributes` whitespace rule preserves those published bytes rather than modifying a dependency for formatting.

The original workspace's **323 recorded files and index remain unchanged**, with no missing files. All pre-existing worktree files remain present. Deliberate changes are owned OCR source/docs/tests and authorized generated output; a generated OCR Python bytecode cache changed during a syntax check and remains untracked with prior QA output. No cleanup deletes user files. Old tracked build versions remain in Git history. The disposable verification listener was stopped; normal manual development listeners remain available. No environment edit or production migration occurred.

## Sequential review and release boundary

Simplify/reuse, repository Standards, approved Spec, risk, TS/React, Karpathy, code-splitting, security/Laravel, dead-code and fresh-verification reviews passed for this scoped handoff. Existing engine, score contract, queue, lifecycle/private viewer, original upload handling and manual decision authority are retained. No new framework, OCR service or dependency was added. Existing customer defaults passed their regression suites. The original decision/requirement/validity/private-access production services are unchanged relative to the target. No parallel reviewers or agents were used.

The observed improvements are functional outcomes, not measured accuracy, fraud rate, speed or device-memory gains. No compiler/lint gate is configured or claimed. Existing Node/local map test warnings, PHPUnit metadata deprecations and the unresolved geometric-pattern SVG build warning remain.

Remaining release checks: Hostinger document-root/MIME/compression/CSP/base-path delivery; real mobile hardware/core/memory coverage; authenticated signed-resubmission/Super Admin and persisted-registration browser journeys; real-camera/ID/two-Cavite-LGU fixtures; and full-project suites. The affected suites above passed, but this is not production-readiness certification. Apply the additive nullable `ocr_screening` migration only through a separately approved deployment before the new backend queries it. Keep report history during rollback.

The final merge remains the user's action. Feature: `codex/shop-owner-registration-ocr`; target: `solespace-b`. Push uses the explicit feature ref without force. No merge or deployment is performed by this workflow.
