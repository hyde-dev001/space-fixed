# Shop-owner OCR merge preparation - October 22, 2026

Status: finalization in progress; no deployment or production migration authorized.

User-authorized sequence: preserve local work, commit only OCR changes, rebase onto freshly fetched `origin/solespace-b`, run affected tests, install the frozen pnpm lockfile, generate and commit fresh production assets, verify the runtime inventory, and push the feature branch without merging or force-pushing.

The worktree initially used `codex/shop-owner-registration-ocr-solspace-b` at `005f1d9cebbdba64cd55ca453c0c1e84ba2477ea`. The requested feature name still pointed to the older `59cc4f20c183038fd3a114e9f8136dfa7222fc9c`; it was preserved under `backup/shop-owner-registration-ocr-before-finalize-20261022`, and the current worktree branch was renamed to `codex/shop-owner-registration-ocr`. Both feature remote names were absent. Fetched target: `e0d8f18143eaeecb7e4e3b9d264ca2f24020104a`.

Preservation inventory: `output/ocr-merge-prep/preservation-before.json` records hashes for the dirty worktree and original workspace; status/index snapshots are beside it. Output artifacts, generated Python bytecode and unrelated original-workspace changes are excluded from staging. Existing files are not cleaned or reset.

The approved browser-only architecture and current document-relevance policy remain authoritative. The recognition fixes, owner/result Swals, private immutable reports and Super Admin projection are included; the decision service remains the only approval/rejection authority. Current operational limits are in the [correction report](2026-10-22-shop-owner-ocr-relevance-fix.md).

Runtime delivery decision: commit the generated `public/ocr/` alongside the fresh `public/build/`, including worker, compatible core/WASM variants, English data, licenses/notices and checksum manifest. Git-based Hostinger deployment must receive these tracked files; no ignored local archive is required. Actual Hostinger delivery/CSP/base-path checks and the additive migration remain separate release prerequisites.

Browser-runner maintenance accounts for the loading/result Swal: pending submission is recorded as blocked by the overlay without claiming the submit handler ran; focused tests independently verify that guard. Active removal invokes the real registered callback behind the overlay without injecting state. Normal completed cases still exercise the form, actual engine and final handler transport capture.

Verification results, rebase outcome and final review will be appended after execution. No completion or deployment-ready claim is made by this draft.
