# Staff Article Screenshots and Plain-Language Copy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all 94 Staff Articles screenshot placeholders with real screenshots from a safe app environment and rewrite the English/Tagalog article copy in simple workplace language.

**Architecture:** Keep the existing typed catalog, article routes, access middleware, and React screenshot/lightbox components. A temporary Playwright capture script will read the catalog screenshot slots, visit the associated Staff-facing pages in a safe environment, and write only the final WebP assets to the existing paths. The catalog keeps its stable IDs and workflow facts while reader-facing copy is simplified in place.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Vitest, Playwright via the repository webapp-testing skill, Python/Pillow or ImageMagick for WebP conversion, browser-native WebP fallback, Git.

## Global Constraints

- Work only in `C:\programmers\xampp\files\htdocs\solespace-master\.worktrees\staff-articles-knowledge-base` on `feature/staff-articles-knowledge-base`.
- Use the approved design at `docs/superpowers/specs/2026-09-05-staff-article-screenshots-and-plain-language-design.md`.
- Capture all 94 declared screenshot slots; do not leave an intentional placeholder for this selected approach.
- Capture only from local, staging, or another explicitly safe environment with sample data; never use production customer or employee data.
- Never store credentials, cookies, Playwright storage state, `.env`, or personal data in the repository.
- Keep screenshot paths exactly `/images/articles/staff/{category}/{article-slug}/{slot-filename}.webp`.
- Preserve slugs, structural IDs, workflow facts, permissions, source-coverage metadata, and exact UI status/button labels.
- Use short sentences, common words, active voice, and natural workplace Tagalog; replace developer terms with words Staff users understand.
- Do not add a database, editor, upload flow, runtime content endpoint, or new runtime dependency.
- Rebase before the final build, include source, screenshots, and fresh `public/build` in one feature commit, and do not force-push.

---

### Task 1: Preflight the safe capture environment and enumerate slots

**Files:**

- Read: `docs/superpowers/specs/2026-09-05-staff-article-screenshots-and-plain-language-design.md`.
- Read: `public/images/articles/staff/README.md`.
- Read: `resources/js/data/staffArticles.ts`.
- Temporary and removed after use: `.tmp-staff-article-capture.mjs`, `.tmp-staff-article-slots.json`, and temporary PNG/contact-sheet output.

**Interfaces:**

- Consumes: `STAFF_ARTICLES`, each article's `screenshots`, `sourceCoverage.routes`, and `sourceCoverage.pages`.
- Produces: a verified 94-slot capture manifest and a confirmed safe `STAFF_ARTICLE_BASE_URL`/test-session setup for Task 3.

- [x] **Step 1: Confirm the branch and clean starting state.**

  Run:

  ```powershell
  git status --short --branch
  git log -1 --oneline --decorate
  ```

  Expected: branch `feature/staff-articles-knowledge-base`; the only local commit beyond the pushed feature is the approved design commit; no unrelated working-tree files.

- [x] **Step 2: Confirm the capture target without creating an environment file.**

  Run:

  ```powershell
  Test-Path .env
  $env:STAFF_ARTICLE_BASE_URL
  python .agents/skills/webapp-testing/scripts/with_server.py --help
  ```

  Expected: either an already-running safe local/staging target is available, or the command reports that the local app lacks its runtime configuration. Do not create `.env`; if no safe target is reachable, stop the capture task and ask the user to provide a reachable safe environment or redacted screenshots.

- [x] **Step 3: Enumerate the exact catalog slots through the Vite/Vitest module resolver.**

  Use a temporary Vitest/Playwright manifest helper rather than importing the TypeScript catalog with plain Node, because the catalog uses Vite-style extensionless imports. Print `{article.slug, screenshot.id, screenshot.path, article.sourceCoverage}` for every slot and assert the total is exactly 94.

  Expected: 94 unique `(article slug, screenshot id)` pairs and paths matching the README contract.

- [x] **Step 4: Verify an image conversion tool is available.**

  Run:

  ```powershell
  python -c "from PIL import Image; print('Pillow ready')"
  magick -version
  ```

  Use the first command that succeeds. Do not install a new runtime dependency for image conversion.

> **Preflight evidence (2026-09-05):** The branch is isolated and the catalog resolver confirmed 94 unique screenshot slots and paths. This worktree has no `.env` and no `STAFF_ARTICLE_BASE_URL`. A temporary SQLite database and process-only Laravel environment were used for capture; no repository environment file, credentials, cookies, or personal data were used. Pillow and ImageMagick were unavailable, so the browser-native WebP conversion fallback was used.

---

### Task 2: Add a failing validation for complete screenshot coverage

**Files:**

- Modify: `resources/js/data/__tests__/staffArticles.test.ts`.

**Interfaces:**

- Consumes: `STAFF_ARTICLES` and the existing bilingual/related/access validation tests.
- Produces: a focused test that proves every catalog screenshot path exists and is a WebP file.

- [x] **Step 1: Add the asset-coverage test.**

  Add a test that flattens all `article.screenshots`, resolves each leading-slash path under `public`, checks `existsSync`, checks the first four bytes are `RIFF`, checks bytes 8–11 are `WEBP`, and asserts that the flattened list has length 94.

- [x] **Step 2: Run the focused catalog test.**

  Run:

  ```powershell
  node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/staffArticles.test.ts
  ```

  Result: all 7 focused catalog tests pass after the screenshot files were captured. The test was added while the capture work was already in progress, so no pre-asset failure was recorded.

---

### Task 3: Capture and validate all 94 real screenshots

**Files:**

- Add: 94 WebP files under `public/images/articles/staff/`, using only catalog-declared category/slug/filename paths.
- Modify: `public/images/articles/staff/README.md` only if capture notes need to document the final capture date or safe-data setup.
- Temporary and removed after use: `.tmp-staff-article-capture.mjs`, raw PNG files, and contact-sheet output.

**Interfaces:**

- Consumes: the Task 1 slot manifest, a safe app URL, a dedicated regular Staff test session, and the current source-coverage route/page references.
- Produces: 94 readable WebP assets that `ArticleScreenshot` loads without its missing-image fallback.

- [x] **Step 1: Build the temporary capture manifest from the catalog.**

  For each slot, assign the Staff-facing route and safe sample state indicated by its article's `sourceCoverage` and step text. Validate each assigned route against `php artisan route:list` and the referenced page file before navigating. Keep the mapping outside committed source so no credentials or test data enter the application.

- [x] **Step 2: Capture with a fixed 16:9 page viewport.**

  The temporary Playwright script must:

  ```javascript
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.goto(`${baseUrl}${route}`, { waitUntil: "networkidle" });
  await page.screenshot({ path: temporaryPngPath, fullPage: false });
  ```

  It must wait for the relevant heading/control/status to be visible, avoid browser chrome, and fail the run if a route redirects to login, shows an application error, or lacks the expected UI state. Use a safe storage state outside the repository; never put it in `public` or Git.

- [x] **Step 3: Convert each captured PNG to WebP without changing the catalog path.**

  Use the available Pillow or ImageMagick tool, preserve readable UI text, and write directly to the catalog-declared `.webp` path. Remove each temporary PNG only after the WebP file exists and its file signature is valid.

- [x] **Step 4: Review the generated images.**

  Create a temporary contact sheet, inspect it with the image viewer, and inspect representative desktop-sized captures individually. Confirm there are no names, email addresses, phone numbers, addresses, order IDs, employee IDs, tokens, credentials, browser extensions, or unrelated tabs. Remove the contact sheet after review.

- [x] **Step 5: Run the asset-coverage test and verify all 94 files.**

  Run:

  ```powershell
  node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/staffArticles.test.ts
  Get-ChildItem public/images/articles/staff -Recurse -Filter *.webp | Measure-Object
  ```

  Result: the focused catalog test passed 7/7; the asset check found 94 files and 0 invalid WebP signatures. A temporary contact sheet and representative captures were reviewed; no missing-image article placeholder was present.

---

### Task 4: Rewrite the bilingual copy in simple workplace language

**Files:**

- Modify: `resources/js/data/staffArticles.ts`.
- Modify: `resources/js/data/__tests__/staffArticles.test.ts` only for focused plain-language assertions that do not reject valid workflow labels.

**Interfaces:**

- Consumes: the existing `StaffArticle` structure and the approved source-coverage metadata.
- Produces: the same 32 articles, slugs, IDs, permissions, screenshots, and workflow branches with clearer English and Tagalog reader copy.

- [x] **Step 1: Search the translations for developer-facing terms before editing.**

  Run:

  ```powershell
  rg -n -i "mutation|payload|endpoint|schema|prop|component|callback|shop-scoped|customer-visible|source relationship|final mutation|operational page boundary" resources/js/data/staffArticles.ts
  ```

  Treat valid UI labels and source metadata as protected text; only rewrite reader-facing translation strings.

- [x] **Step 2: Rewrite English copy with direct, common words.**

  Use terms such as `save`, `details`, `for this shop`, `where the stock came from`, `who handles it next`, and `what the customer sees`. Keep sentences short, name the action first, and keep exact labels such as `"Pending"`, `"Processing"`, `"Under Review"`, and `"Submit"` when the screen uses them.

- [x] **Step 3: Rewrite Tagalog copy in natural workplace Tagalog.**

  Keep the same structural IDs and number of prerequisites, workflow states, steps, outcomes, errors, and related links as English. Use familiar words such as `i-save`, `mga detalye`, `para sa shop na ito`, `sino ang susunod na gagawa`, and `ano ang makikita ng customer`; retain exact English UI labels in quotation marks where Staff must find them.

- [x] **Step 4: Validate structural parity and search behavior.**

  Run:

  ```powershell
  node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/staffArticles.test.ts
  ```

  Result: 32 unique articles remain, both languages have matching structural IDs, all related links resolve, access filtering is unchanged, searches still find ordinary English/Tagalog status and rejection terms, and every declared screenshot path has a WebP asset.

---

### Task 5: Run the final gate, commit, and push the completed update

**Files:**

- Modify: `docs/superpowers/plans/2026-09-05-staff-article-screenshots-and-plain-language.md` with completed checklist/evidence.
- Include: `resources/js/data/staffArticles.ts`, validation tests, all 94 WebP assets, and a fresh `public/build` in one feature commit.

**Interfaces:**

- Consumes: completed screenshot assets, simplified catalog copy, and the existing Staff Articles feature.
- Produces: a pushed `feature/staff-articles-knowledge-base` branch ready for the user's PR.

- [x] **Step 1: Fetch and rebase before the final build.**

  Run:

  ```powershell
  git fetch origin --prune
  git rebase origin/solespace-b
  ```

  If the worktree is dirty, stage only the intended source/image changes temporarily or use Git's safe autostash; never reset or force-push.

- [x] **Step 2: Run focused and full checks after the final copy/image changes.**

  Result: focused catalog tests passed 7/7; the Articles page tests passed 5/5; the full frontend suite passed 204 files and 1,161 tests; the relevant Laravel route test passed 6 tests and 41 assertions with 6 PHPUnit warnings.

- [x] **Step 3: Generate the fresh production bundle once after the rebase.**

  Run:

  ```powershell
  pnpm install --frozen-lockfile
  pnpm run build
  ```

  Result: `pnpm run build` was blocked by the local PowerShell execution policy; `pnpm.cmd run build` reached pnpm but was blocked by its registry-signature verification. The installed local equivalent `node node_modules/vite/bin/vite.js build` completed successfully, transforming 3,788 modules in 22.39 seconds.

- [x] **Step 4: Review and stage only intended files.**

  Run:

  ```powershell
  git status --short
  git diff --check
  git add -- docs/superpowers/plans/2026-09-05-staff-article-screenshots-and-plain-language.md resources/js/data/staffArticles.ts resources/js/data/__tests__/staffArticles.test.ts resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx public/images/articles/staff public/build
  git diff --cached --check
  git diff --cached --name-status
  ```

  Confirm no unexpected deletions exist outside generated `public/build`, no credentials or temporary files are staged, and all 94 WebP files are included.

  Result: staged only the plan, article catalog, two related tests, 94 WebP screenshots, and regenerated `public/build`; no temporary files, credentials, `.env`, or runtime storage were staged.

- [x] **Step 5: Commit source, screenshots, and build together.**

  Use:

  ```powershell
  git commit -m "feat: add staff article screenshots and plain copy"
  ```

  Result: created one feature commit containing the article catalog copy, validation tests, 94 screenshots, the implementation plan, and the regenerated `public/build`.

- [x] **Step 6: Verify the commit and push the feature branch.**

  Run:

  ```powershell
  git diff --check origin/solespace-b...HEAD
  git push --progress -u origin feature/staff-articles-knowledge-base
  git status --short --branch
  ```

  Expected: push succeeds, the branch tracks `origin/feature/staff-articles-knowledge-base`, and the worktree is clean. Do not create the PR.

  Result: verified the commit, pushed the feature branch, and confirmed its remote tracking and clean worktree; the PR remains for the user to create.
