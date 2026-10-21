# Shop Owner Registration OCR operations

Date: 2026-10-21. This guide describes the implemented browser-only screening and the evidence collected in the isolated development worktree. It does not authorize a deployment or production migration.

## What the score means

The applicant's browser runs local Tesseract.js over the selected required document image. The browser sends only a bounded evidence report; Laravel validates its shape and derives a categorical result attached to the immutable uploaded document version.

- **100% - Automated Checks Passed** means every applicable required automated check had sufficient matching evidence. It does not verify authenticity and never approves an application.
- **50% - Requires Further Review** means usable reading exists but evidence is partial or uncertain.
- **0% - Conflicting Evidence Detected** means reliable localized evidence conflicts with a required check. The application remains eligible for human review.
- **Not assessed**, **Unable to assess**, **Needs rescan**, and **Assessment incomplete** remain distinct states. Missing assets, OCR errors, or a score never replace the existing admission and review rules.

The existing authorized Super Admin workflow remains the only approval/rejection authority. Admins still inspect every required private document and supply the existing reviewer metadata. Browser evidence is applicant-controlled and preliminary.

## Build and package the local OCR assets

Use the pinned lockfiles and Node build tooling:

```powershell
pnpm install --frozen-lockfile
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
```

`pnpm run build` and `pnpm run dev` explicitly run `scripts/prepare-owner-ocr-assets.mjs`. The current bundle uses Tesseract.js/core 7.0.0 and English data 1.0.0. The generated `public/ocr/manifest.json` points to versioned same-origin worker, core, and language paths and records asset checksums. There is no runtime CDN fallback.

`public/ocr/` is intentionally Git-ignored and generated from the locked packages. A release/archive process must explicitly include both `public/build/` and the complete `public/ocr/` directory while preserving their paths under Laravel's public web root. Do not deploy only the tracked application files or only the Vite manifest. Keep the OCR manifest and its files from the same build. `node scripts/prepare-owner-ocr-assets.mjs --check` verifies the local generated assets before packaging.

The development verification created a temporary archive containing both directories and confirmed all 24 generated OCR files were included. It did not exercise Hostinger's actual package/upload flow or server. Before release, verify the archive produced by the real deployment process and request the worker, selected core, and compressed English model from the target host. Confirm the host serves JavaScript with an executable JavaScript MIME type and does not rewrite or strip the gzip model.

The bootstrap builds same-origin asset URLs and has a backend test for a `/portal` base path. Browser verification used a root-mounted local app; subdirectory hosting remains a release check. Confirm the deployed Content Security Policy permits the worker and WebAssembly behavior required by the installed browser/core combination. No CSP header was available in the local browser run, so this compatibility is not yet established.

## Release prerequisites and migration boundary

The release must contain the matching PHP/frontend code and generated OCR assets. Apply the additive nullable `ocr_screening` migration only under a separately approved production release procedure and before application code expects the column. No backfill is required; existing rows remain unassessed. This execution did not run a production or shared database migration.

After deployment, verify asset availability under the actual public base path, one cold-cache scan with a synthetic permitted fixture, graceful failure when a local asset is blocked, and a Super Admin review that still requires the original document viewing and reviewer metadata. Do not upload applicant images or extracted OCR text to third-party services. Do not use OCR scores for automatic approval, rejection, sorting, or access grants.

## Failure handling, privacy, and rollback

If the browser or a local asset is unavailable, the UI reports that it could not assess the document and the applicant can continue through the existing required validation. Unfinished or stale browser evidence is omitted at submit. Malformed evidence that is actually submitted is rejected by normal Laravel validation. Raw OCR text, extracted names/addresses, ID/TIN values, and word locations are not persisted in the report.

If the feature must be disabled, roll back the application/frontend release while retaining the nullable column and stored reports. Do not drop the column or erase report history as an automatic rollback. Existing private documents, viewed-document rules, registration status, decision audits, and customer verification are independent of the OCR report.

## Verification limits recorded on 2026-10-21

The production build passed, and Chromium/WebKit loaded the local OCR engine against hand-authored, watermarked synthetic fixtures. Local worker/core/language responses and a temporary archive layout were checked. These are functional checks only; OCR accuracy, fraud detection, peak device memory, real Hostinger delivery, production CSP, browser subpath hosting, and authenticated registration/resubmission/admin browser journeys remain unverified. See [the execution record](shop-owner-registration-ocr-execution.md) for test results, acceptance status, and blockers.
