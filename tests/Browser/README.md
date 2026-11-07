# Shop-owner OCR form verification

`shop_owner_ocr_relevance.py` exercises the real local React form and local Tesseract worker/core/model. It generates only synthetic document, portrait, cartoon, landscape and text images. It completes ordinary OTP verification using a loopback SMTP capture; OTPs remain in memory. No authentication route or middleware is bypassed.

Use a **disposable, already migrated SQLite test database** at `output/ocr-relevance-fix/browser.sqlite`. Copy/SQLite-backup a known local test fixture, never the configured production database. The helper does not initialize, migrate, reset or delete a database. Confirm ports 8017, 5187 and 1025 are free before starting; do not let a server automatically select another port.

From the worktree root, start the worktree's existing Vite tooling on a free port (5187 in the recorded run), then the test HTTP server:

```powershell
pnpm run dev --host 127.0.0.1 --port 5187 --strictPort
php -S 127.0.0.1:8017 -t public tests/Browser/shop_owner_ocr_server.php
```

Run the browser check in a separate terminal with Python Playwright/Chromium already available:

```powershell
python tests/Browser/shop_owner_ocr_relevance.py --mode verify
```

The PHP runner is outside `public/`, accepts loopback clients, fixes database/mail configuration before app boot and preserves the normal HTTP kernel. The browser requires its isolation header before making OTP requests. No external email is needed. Do not deploy this runner as an application entry point.

The case matrix observes real component state without writing it and captures the actual handler's Inertia transport after validation/confirmation/CSRF retrieval. This avoids backend registration writes and notifications; it is not backend authorization evidence. Existing Laravel admission/persistence/decision/private-access tests cover those boundaries separately. No OCR outputs are mocked for recognition cases. The missing-worker and slow/pending cases deliberately disturb asset delivery and record that condition.

The owner loading Swal now covers background controls during active scans. The pending case records `not_reached_loading_dialog`: the submit button is unreachable, and this case does not claim the submit callback ran. Focused frontend tests independently cover that handler guard. Active removal deliberately invokes the real remove button callback behind the overlay to test cancellation without injecting state. Completed scans dismiss their result Swal before navigation. These distinctions preserve the assertions while following the current interface.

Safe result summaries, original synthetic file hashes, asset responses and screenshots are written under `output/ocr-relevance-fix/`. No raw applicant OCR, OTP, credentials or private document data belongs there. The `baseline` mode is for reproducing the earlier behavior and must not overwrite the recorded before-fix evidence during ordinary regression runs. `--mode verify` must pass before claiming the browser gate is verified.

For a stable matrix, pause source/asset changes and do not run backend bootstrap tests or builds concurrently: they can replace the asset manifest and trigger Vite reloads. The recorded run used an output-local Vite config importing the existing `vite.config.js`, with `cacheDir: 'output/ocr-relevance-fix/vite-cache'`, `server.hmr: false`, port 5187/strictPort, and `**/output/**` ignored by the watcher. Start it with `pnpm exec vite --config output/ocr-relevance-fix/vite.verify.config.mjs --host 127.0.0.1`. This freezes development reloads only; the application form, engine, middleware and validation are unchanged. Restore the ordinary dev command for interactive editing after verification.
