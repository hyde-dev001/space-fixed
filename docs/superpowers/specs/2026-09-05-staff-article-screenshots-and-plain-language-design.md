# Staff Article Screenshots and Plain-Language Copy Design

**Date:** 2026-09-05
**Status:** Proposed for review
**Scope:** Complete the existing regular Staff Articles catalog with real screenshots and simpler reader-facing copy.

## Goal

Replace all 94 screenshot placeholders in the Staff Articles catalog with screenshots of the real SoleSpace Staff-facing pages, captured with safe sample data. Rewrite the English and Tagalog article copy so frontline Staff can follow it without developer or system jargon.

## Selected approach

Use an automated browser capture pass for every catalog screenshot slot. The capture process will use an accessible local or non-production environment with a dedicated Staff test account and sample records. It will save each image to the exact path already declared by the catalog:

```text
public/images/articles/staff/{category}/{article-slug}/{slot-filename}.webp
```

The 94 slots stay complete and individually addressable. The screenshots will use a consistent 16:9 page viewport and show the exact button, tab, status, validation message, or workflow state that the related step describes. Browser chrome, unrelated tabs, extensions, and personal data will not appear.

## Safe capture boundary

- Capture only from local, staging, or another explicitly safe environment.
- Use sample records and a dedicated regular Staff test account. Do not use production customer, employee, payment, address, credential, token, or shop data.
- Do not store login credentials, browser session files, cookies, or environment files in the repository.
- If a screen contains sensitive values, replace them with sample values before capture or redact them before saving.
- The agent cannot take over an already logged-in personal browser window. The capture environment must be reachable by the automated browser or the user must provide already-redacted screenshots.
- Every generated file must be reviewed for accidental PII or secrets before staging.

## Copy rewrite rules

The article structure, slugs, workflow facts, permissions, status labels, and source references remain unchanged. Only reader-facing wording is simplified.

- Use short sentences, common words, active voice, and direct instructions.
- Explain a technical term the first time it is needed, then use the simpler word.
- Keep exact UI labels in quotation marks when Staff must look for that label, such as `"Pending"`, `"Processing"`, or `"Submit"`.
- Prefer `who handles it next` over `next owner`, `what the customer sees` over `customer-visible result`, `for this shop` over `shop-scoped`, `save` over `mutation`, `stock source` over `source relationship`, and `details` over `context`.
- Write Tagalog in natural, plain workplace language. English button and status labels may remain in quotation marks when they match the screen.
- Keep both language branches structurally identical. Do not silently remove a step, outcome, error, or recovery path from either language.
- Do not change operational behavior or turn the articles into an authorization mechanism.

## Implementation and verification

1. Map each screenshot slot to its current page, route, permission, and required sample state using the catalog source-coverage metadata.
2. Run a temporary Playwright capture script against the safe environment, waiting for the page to settle before taking each screenshot.
3. Save and inspect all 94 WebP files at their catalog paths. Missing assets are not acceptable for this selected approach.
4. Rewrite and review all English/Tagalog title, question, summary, step, workflow, outcome, and error text for plain language while preserving structural IDs and exact system labels.
5. Add focused validation that every declared screenshot path exists, uses the expected WebP filename/path contract, and that bilingual structural parity remains intact.
6. Run focused and full relevant tests, production build, and `git diff --check`. Include the fresh `public/build` output in the same commit as the source and screenshot changes.

## Acceptance criteria

- All 94 declared screenshot slots display an actual, readable screenshot instead of the missing-image placeholder.
- Screenshot paths and filenames match the existing catalog contract.
- Screenshots contain no production data, PII, credentials, tokens, or unrelated browser content.
- English and Tagalog copy uses simple workplace language and still explains the same workflows, outcomes, errors, and recovery steps.
- Existing access controls, route behavior, and article navigation remain unchanged.
- Tests, build, diff checks, and screenshot review pass before the updated feature branch is pushed.
