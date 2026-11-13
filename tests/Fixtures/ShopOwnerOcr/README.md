# Shop-owner OCR contract fixtures

These JSON fixtures contain synthetic evidence values only. They contain no document images, personal identifiers, OCR output, or real applicant information. The cases are test inputs for the fixed evidence and score contract, not measurements of OCR quality or fraud detection.

`screening-contract.json` is consumed by both the TypeScript and PHP tests. It covers the four required logical slots, DTI/SEC selection, conditional sole-proprietor evidence, local-confidence boundaries, missing evidence, unavailable scans, and aggregate precedence. `recognition-evidence.json` inventories synthetic pass-local examples for complete, ambiguous, role-specific, contradictory, unsupported-ID, localized-nationality, and independent-type evidence. Recognizer tests exercise the corresponding synthetic labeled lines and assert the fixture inventory remains present; this file is not OCR output or an image fixture.

`images/` contains ten hand-authored SVG text fixtures. Their origin is this repository's synthetic test data; no redacted source or government template was used, so no external permission is needed. Every image is watermarked **SYNTHETIC TEST SAMPLE - NOT AN OFFICIAL DOCUMENT**, uses invented names/addresses/references, and has no official mark, signature, QR code, or real personal information.

| File | Expected type evidence / test interpretation |
| --- | --- |
| `dti-registration.svg` | DTI issuer, business-name certificate, registered-name/proprietor/date fields. |
| `sec-registration.svg` | SEC issuer, incorporation certificate, legal entity and explicit trade-name fields. |
| `mayors-permit-imus-layout.svg` | Municipality heading and inline business-name/address/dated-validity fields. |
| `mayors-permit-demo-layout.svg` | City heading and separately phrased permit-reference/trade-name/premises/validity fields. |
| `bir-form-2303.svg` | BIR/Form 2303, non-individual taxpayer, business/address, registration date; no invented annual expiry. |
| `national-id-filipino.svg` | PSA National ID with localized Filipino nationality and holder fields; no printed expiry. |
| `drivers-license.svg` | LTO driver's license with holder and dated-validity fields. |
| `passport.svg` | Philippine passport issuer/title, holder and dated-validity fields. |
| `umid.svg` | SSS/UMID title, holder and common-reference structure. |
| `unsupported-government-id.svg` | Plausible government ID outside the supported families; recognition must stay uncertain. |

These files are synthetic inputs for later actual-browser Tesseract checks, not evidence of production accuracy or authenticity. They intentionally do not mimic official layouts or certify document identity.

The browser runner `tests/Browser/shop_owner_ocr_relevance.py` renders these SVGs into PNG uploads and generates procedural portrait/cartoon/landscape, blank, shopping-list, blurred and conflicting-field variants. These generated files contain no real person's photograph or private document. A procedural portrait is a synthetic representation of a selfie, not coverage of real camera photographs. Safe engine/form outcomes and remaining limits are recorded in `docs/operations/2026-10-22-shop-owner-ocr-relevance-fix.md` and `output/ocr-relevance-fix/browser-results.json`.
