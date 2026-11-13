# Deployed shop-owner registration 403 investigation

Started October 22, 2026. Latest update October 23: **a filename-specific 403 is reproduced, and the application-side multipart compatibility fix passes tests and a real-browser persisted registration**. The fix is local and rebuilt; it has not been deployed. See the final section. No production configuration, database, application deployment, or security setting was changed.

## Reported failure

The applicant's POST to `https://solespace.shop/shop-owner/register` returns generic HTML titled `403 Forbidden`, with `Access to this resource on the server is denied!`. The subsequently supplied response headers identify the failed request as follows:

| Supplied evidence | Value |
| --- | --- |
| Response date | `Thu, 22 Oct 2026 15:56:38 GMT` (23:56:38 Singapore time) |
| `x-hcdn-request-id` | `c9d341e53d0a9a46e04a45e5572649d5-kul-edge4` |
| `server` / platform | `hcdn` / `hostinger`, panel `hpanel` |
| Response content type / encoding | `text/html` / `zstd` |
| Response cache control | `private, no-cache, no-store, must-revalidate, max-age=0` |
| Request Accept | `text/html, application/xhtml+xml` |
| Reported remote address | `145.79.29.187:443` |

Request length and the matched hosting log/security-rule identifier remain unavailable. The error resembles a web-server denial, but that resemblance alone does not establish a WAF rule, file-size restriction, IP restriction, or Laravel cause. The remote address is the server contacted by the browser, not the applicant's client IP. A Hostinger CDN response header identifies transit through that CDN, not necessarily which layer generated the error.

The separate local failure was confirmed as a missing `shop_documents.ocr_screening` column and repaired only on the verified local database. That result does not diagnose the deployed 403.

## Live checks performed

Requests used an ordinary anonymous session and the public CSRF endpoint. Every POST deliberately omitted required applicant fields, including email, so none could create a registration. File cases contained four synthetic one-pixel PNGs, not applicant documents. OCR metadata came from synthetic contract fixtures. Cookies, tokens, raw applicant values, and document contents were not recorded.

| Check | Actual response |
| --- | --- |
| GET `/shop-owner-register` | 200, Laravel/Inertia page |
| GET `/shop-owner/register` | 405, `Allow: POST` |
| GET `/api/csrf-token` | 200, JSON; token not recorded |
| POST empty JSON with normal session/CSRF | 422, required-field validation |
| POST incomplete multipart text | 422, required-field validation |
| POST multipart with four synthetic PNGs, 2,856 bytes | 422, required-field validation |
| POST four PNGs plus four-entry OCR JSON, 5,925 bytes | 422, required-field validation |
| Same multipart with Inertia/XHR headers | 422, required-field validation |
| POST empty JSON without session/CSRF | 419, `CSRF token mismatch.` |
| GET `/build/manifest.json` | 200, byte-identical to feature-worktree manifest |
| GET `/ocr/manifest.json` | 200, byte-identical to feature-worktree manifest |
| Incomplete multipart with reported HTML Accept | 302, expected redirect to registration page |
| HTML Accept, Inertia/XHR/version headers, four synthetic files | 302, expected validation redirect |
| Same HTML/Inertia headers, four files plus OCR report | 302, expected validation redirect |

The response headers identify Hostinger (`platform: hostinger`, `panel: hpanel`, `server: hcdn`). The initial public checks occurred at approximately 15:50 UTC / 23:50 Singapore time. These checks establish that registration POSTs are not universally blocked and that ordinary validation and CSRF rejection work for these diagnostic requests. They do not prove that a full registration, the applicant's payload/IP/session, large uploads, backend source, database schema, or every deployed asset is correct. Initial field validation exits before report persistence in these incomplete requests.

Manifest SHA-256 values:

- Build: `a27bdf42bdb6fe7cd6280862e090adfb81bb9f773b4e45bcd11f4b965000a106`.
- OCR: `365c637f8645629f7b52e7f611e7352a209c7759a78d81e4019e2f9082b77565`.

## Evidence needed to complete the fix

The failing response date, server and request ID have now been supplied. Match **that exact request** to Hostinger access/error and WAF/ModSecurity logs and the Laravel log. Total upload/request size is still needed for a size-limit hypothesis. Share only the relevant error/rule identifiers and sanitized message, without cookies, CSRF tokens, email/OTP contents, or document payloads.

No Hostinger/SSH connection is available in this session; browser-control initialization also failed before it could inspect any hosting session. Public responses do not expose the required private server logs. Supplying the response headers enables a precise support/log lookup but does not by itself resolve the incident.

After receiving the actual Accept header, three additional production diagnostics at `15:58:57 UTC` used that exact HTML Accept, including current Inertia version/XHR headers where applicable. Redirects were not followed; all three returned 302 to `/shop-owner-register`, not 403. The files/report cases were 2,856/5,925 bytes and still omitted email and other mandatory fields. Safe summaries are in `output/deployed-registration-403/html-header-checks.json`; no applicant registration was created. These results exclude the reported Accept header alone as a reproduced trigger, but do not exercise the applicant's full request or establish that larger uploads work.

If a log identifies a false-positive rule, reproduce its triggering legitimate field/file and apply the smallest supported rule correction for that specific case. Do not disable the WAF globally, exempt the registration route wholesale, remove CSRF, encode data to evade inspection, or retry registrations automatically. If the request reaches Laravel, investigate its actual error instead. Check the deployed OCR migration status separately through the approved release procedure; no production migration is authorized by this investigation.

Suggested hosting-support investigation text (draft only, not sent):

> A legitimate multipart POST to `https://solespace.shop/shop-owner/register` returned a generic HTML 403 at `2026-10-22 15:56:38 UTC`. Its `x-hcdn-request-id` is `c9d341e53d0a9a46e04a45e5572649d5-kul-edge4`. Small synthetic requests to the same endpoint reach Laravel and return expected validation responses: 422 with JSON Accept or 302 with the applicant's HTML Accept and Inertia headers, including four synthetic files and OCR metadata. Please correlate the failing ID/time with CDN/origin/security logs, identify which layer blocked it and the matched rule/parameter or limit, and correct a confirmed false positive as narrowly as possible while retaining security protections. Please do not broadly exempt all registration requests or disable the WAF.

References: [LiteSpeed 403 troubleshooting](https://docs.litespeedtech.com/lsws/cp/cpanel/403-error/) describes several distinct causes and log-based diagnosis; [Hostinger 403 troubleshooting](https://www.hostinger.com/support/1583304-how-to-fix-a-403-forbidden-error-at-hostinger/) likewise lists multiple server/configuration causes. Neither source identifies which cause applies to this incident.

Hostinger's more specific [CDN error guide](https://www.hostinger.com/support/hostinger-cdn-troubleshooting-website-errors/) explicitly says the request-ID header identifies CDN transit, including origin-generated errors. Its [blocked legitimate requests guide](https://www.hostinger.com/support/hostinger-cdn-how-to-fix-blocked-legitimate-requests/) explains that support can use the failing method/URL/time/ID to identify a triggered rule and that precise service/endpoint exceptions cannot be configured from the hosting dashboard. No CDN level, traffic rule, origin setting or security exception was changed in this investigation.

## Initial scope and review, before filename reproduction

Sequential review: preserve unrelated work; compare the actual registration route, multipart helper, CSRF helper, middleware and exception handling; retain all validation/private-upload/manual-decision requirements. No application fix is justified yet by the available evidence. Application code, dependencies, environment files, generated assets and authorization rules remain unchanged. This documentation records an unresolved incident, not a completed deployment repair. No new build or application-test pass is claimed for these read-only/source-inspection and deliberately invalid HTTP checks.

## October 23 follow-up: audit the OCR merge as a possible cause

The user reports that registration worked before the OCR merge and confirms the OCR migration was run in production. Treat that history as relevant evidence; do not rule out an application regression merely because the returned HTML resembles a server error. The production migration's completion is user-reported, not independently inspected in this session.

The source comparison against local `origin/solespace-b` isolates the admission changes: optional `ocr_screening` string validation, bounded evidence validation before the existing workflow, optional per-slot evidence attached to immutable batch creation, and the new nullable-column dependency. The multipart helper still sends the original files and metadata and adds only optional OCR JSON. The frontend adds relevance guards and submits to the same endpoint with the existing fresh-CSRF helper. No registration-route, `.htaccess`, session-configuration or shared employee-middleware change appears in this OCR delta, and the reviewed admission changes introduce no 403 response. This is source review of the worktree, not proof that deployed PHP matches it.

### More realistic production payload comparison

Two additional deliberately invalid production requests used the repository's four synthetic document PNGs, normal synthetic form/date/type fields, UUID submission keys, HTML Accept and Inertia headers. Both omitted email, making account creation impossible. The baseline omitted OCR JSON; the comparison included the four-entry synthetic contract report. This tests payload transport/filtering, not successful admission, since required-field validation still exits before report persistence.

| Payload | Size | Response | Time UTC |
| --- | --- | --- | --- |
| Pre-OCR payload shape | 759,049 bytes | 302 validation redirect | October 22, 16:06:38 |
| Same shape plus OCR report | 762,145 bytes | 302 validation redirect | October 22, 16:06:39 |

Safe summaries: `output/deployed-registration-403/realistic-payload-comparison.json`. These requests did not reproduce the applicant's 403. Their success does not establish that the applicant's actual field values, files, sizes, session or network will pass.

### Actual local browser submission and persistence

`python -B output/ocr-deployment-code-audit/persist_registration.py` ultimately completed with **exit 0** using the production frontend build, actual browser Tesseract, normal OTP send/verify, normal map/location and terms validation, and the actual HTTP submission. The Inertia transport was **not** intercepted or replaced. The PHP listener on loopback port 8029 used a disposable SQLite copy, output-local private storage, and loopback SMTP capture; all application routes/middleware remained normal.

The initial attempts are failures, not passes: the first was rejected by the normal address guard when an uncached snapped-coordinate lookup was unavailable; another used a development React-name observer against the minified production component and could not observe completion; a later full POST revealed that the old copied SQLite fixture lacked `shop_owners.repair_warranty_duration_unit`. No registration was created in those attempts. The normal geocoder service was used to obtain/cache a real provider response for the public synthetic location, the harness switched to visible loading-state waiting, and existing pending migrations were applied only to the guarded disposable SQLite copy. No location rule was relaxed, fake geocoder response injected, migration created, or production/shared database migrated.

With the test schema current, the complete browser request returned **302**, reached `/shop-owner/pending-approval`, and created exactly one pending application with four pending, non-current private versions:

| Document | Stored preliminary score | Original file verified |
| --- | --- | --- |
| DTI | 100 | SHA-256 agrees with synthetic upload |
| Mayor's Permit | 50 | SHA-256 agrees with synthetic upload |
| Form 2303 | 50 | SHA-256 agrees with synthetic upload |
| National ID | 100 | SHA-256 agrees with synthetic upload |

All four reports were stored as `assessed`, source `browser_tesseract`. No application was approved or rejected. Safe result: `output/ocr-deployment-code-audit/result.json`. This closes the local persisted-submission gap for this synthetic case only; it does not repair or reproduce the production incident, test actual production persistence, or establish OCR accuracy. No production registration or external email was created by the test.

Application code, dependencies, environment settings and build artifacts remain unchanged during this follow-up. The remaining discriminators include the applicant's actual request/file sizes/types and the exact failed request's server/security log. A request for sizes/types is pending. The production 403 remains unresolved; no speculative WAF exception or application workaround has been shipped.

## October 23: filename trigger reproduced and corrected

The user identified `C:\Users\slyha\Downloads\asd` as the source of the failing uploads. Read-only inventory found five JPEGs between 46,912 and 436,335 bytes, including `Mayor's Permit  Business Permit 1.jpg` and `Mayor's Permit  Business Permit 2.jpg`. No originals were modified or uploaded by this investigation. Instead, a tiny synthetic PNG was sent under those names to isolate filename behavior from document contents and OCR evidence. All requests deliberately omitted required applicant fields so they could not create an account.

| Multipart filename, same synthetic bytes | JSON Accept result |
| --- | --- |
| `mayors-permit.jpg` | 422, normal Laravel validation |
| `Mayor's Permit  Business Permit 1.jpg` | 403, generic HTML |
| `Mayor's Permit  Business Permit 2.jpg` | 403, generic HTML |
| `Mayors Permit  Business Permit 1.jpg` | 422, normal Laravel validation |
| `Mayor_s Permit  Business Permit 1.jpg` | 422, normal Laravel validation |

The final comparison also used the applicant's HTML Accept and Inertia headers at `2026-10-22 16:39:15 UTC` / October 23, 00:39:15 Singapore time. The apostrophe name returned **403**, request ID `dc94e12278404f2bdcd7310b97fc8e14-kul-edge4`; the otherwise identical underscore name returned **302** to the registration page, request ID `f04a31aef3907fb190332732db515ce2-kul-edge5`. No OCR JSON was required to reproduce the block. This proves a filename-specific failure in the hosting request pipeline, not that the OCR recognition/scoring logic or merge itself introduced it. The exact hosting rule/layer remains unconfirmed without private logs.

Safe evidence is in `output/deployed-registration-403/filename-comparison.json`, `apostrophe-isolation.json`, and `final-html-filename-comparison.json`. Original-file names/sizes/hashes are inventoried in `actual-file-inventory.json`; there are no document copies or raw extracted values in these summaries.

### Minimal implementation

`registrationDocumentPayload.ts` now uses one private `appendDocumentFile` helper for required and optional owner uploads. It replaces apostrophes with underscores **only in the multipart filename**. Files without apostrophes follow the original append path and keep their object identity. The selected File, original name shown in the UI, image bytes, MIME type, extension, slots, metadata, submission keys, OCR report, signed endpoint, Laravel validation and private version workflow stay intact. Customer upload code is not changed. This is filename normalization at the existing upload boundary; it does not encode document/report contents, disable inspection, or exempt the endpoint from security checks.

Three new table-driven regressions cover both supplied permit names and a PNG attachment, all four required fields and supporting documents, exact byte equality, MIME/size and original-name preservation. They first failed on the unchanged apostrophe filename, while the four existing cases passed. After the fix, all seven payload tests passed.

### Fresh verification after the correction

- Focused frontend: **4 files / 43 tests passed** (`ShopOwnerRegistrationDocuments`, `ShopOwnerRegistrationOcr`, `ShopOwnerRegistrationOptionalDocuments`, `useShopOwnerOcr`).
- Broader affected frontend: **23 files / 280 tests passed**, including owner registration, customer OCR/defaults, Super Admin review and DropZone.
- Laravel: **31 tests / 196 assertions passed** for `ShopOwnerOcrPersistenceTest`, `ShopOwnerAuthRegistrationTest`, and `ShopOwnerRegistrationEmailVerificationTest`, using testing SQLite.
- `pnpm run build`: **exit 0**, 3,851 modules, **41.82 seconds**, fresh tracked `public/build/`. The existing `/images/auth-geometric-pattern.svg` warning remains. No dependency or lockfile changed.
- `node scripts/prepare-owner-ocr-assets.mjs --check`: passed for the unchanged pinned runtime bundle. All 401 build-manifest entries reference existing files; the owner's 40 static chunks still exclude the deferred OCR reader.
- `python -B output/ocr-deployment-code-audit/persist_registration.py`: **exit 0** against the rebuilt frontend and disposable local app. Normal OTP, map/location, terms, real Tesseract, actual HTTP and pending persistence all ran. The original apostrophe filename remained visible in the upload preview. The local receiving PHP request observed `Mayor_s Permit  Business Permit 1.jpg`; its uploaded checksum agreed with the selected synthetic image. Four private original hashes and assessed reports were preserved, with scores DTI 100, permit 50, BIR 50, ID 100. No automatic approval/rejection or production registration occurred. Result: `fixed-filename-result.json`.

The first filename-observation harness attempt could not retrieve multipart file bytes through Playwright's `request.post_data_buffer`; that assertion failure is not counted as a verification pass. The final run observes only filenames/checksums at the disposable receiver before normal kernel handling; it neither intercepts transport nor changes uploads or validation. Test-created rows/files stay only in the disposable output database/private directory. Both audit listeners were stopped after their respective runs; the manual app was not stopped.

Frontend commands use the existing cached `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...` launcher. Exact broader command/results remain in `frontend-regressions.log`; backend command/results are in `backend-regressions.log`, under `output/deployed-registration-403/`. Existing Node localStorage, missing local CARTO configuration, jsdom canvas and PHPUnit metadata warnings are not new failures. No compiler/lint or full-project-suite pass is claimed.

### Review and handoff

Sequential review: simplify (one helper, two existing upload sites, no dependency); Standards, Spec and risk (only transport filename changes); TS/React (typed File/FormData boundary, no new assertions/any or component state); Karpathy (reproduce one variable, failing tests, minimum change); splitting (unchanged lazy reader verified by manifest); measured behavior (403 to expected 422/302 for the same bytes); security (extension/MIME/bytes and all admission/approval protections retained); fresh verification (tests/build/browser evidence above). Reuse/dead-code review confirms both file-append sites use the helper and no customer caller or obsolete branch was introduced. Diff hygiene passes. No unrelated file was staged or overwritten.

The rebuilt fix still needs the separately authorized deployment of the matching frontend assets before the live form automatically normalizes filenames. No production PHP or migration change is needed for this filename correction. As an immediate manual workaround, upload an unchanged file renamed from `Mayor's Permit...jpg` to `Mayor_s Permit...jpg` or `Mayors Permit...jpg`. This addresses the demonstrated filename trigger; a complete successful production registration after that change remains unverified, and other independent 403 causes are not excluded. No push, merge, deployment, security-rule change or production migration was performed for this correction.

### Commit/push handoff preparation

The user subsequently authorized committing and pushing this correction with a fresh production build. Fetch confirmed `origin/solespace-b` at `78cc130e84d3722d101b3621ba505cff72eb1eec`, the merge of the previous OCR work via PR #733. Its committed tree matched the feature branch, so only `codex/shop-owner-registration-ocr` was fast-forwarded to that existing target commit before the new fix was committed. No source conflict or target-branch mutation occurred.

Fresh handoff checks: frozen-lockfile install passed without package/lock changes; the four focused frontend suites passed **43 tests** again; `pnpm run build` completed successfully in **36.02 seconds**. The broader **280 frontend / 31 Laravel** results and successful rebuilt-browser persistence above apply to the same unchanged fix. Only the payload helper, its regressions, OCR operations/incident documentation and the complete regenerated `public/build/` are included in the handoff. The 24 tracked OCR runtime files remain unchanged. Output artifacts and Python bytecode stay untracked and outside the commit. This authorizes feature-branch publication, not deployment or an automatic merge into `solespace-b`; production registration after deploying the fix remains a follow-up check.
