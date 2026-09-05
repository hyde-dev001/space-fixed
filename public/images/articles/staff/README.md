# Staff Articles screenshots

This directory contains optional, static screenshots used by the authenticated Staff Articles knowledge base.

## Path and filename contract

Place each WebP file at:

`/images/articles/staff/{category}/{article-slug}/{slot-filename}.webp`

The exact category, slug, slot filename, alt text, and aspect ratio are defined in `resources/js/data/staffArticles.ts`. Keep filenames lowercase and use the existing `step-01-...`, `step-02-...`, or `step-03-...` slot name from the catalog.

## Capture and redaction rules

- Capture the current Staff-facing page at a useful desktop or mobile width with representative sample data only.
- Redact customer names, email addresses, phone numbers, addresses, order references, employee identifiers, tokens, and any other personal or secret value.
- Do not capture production credentials, browser extensions, unrelated tabs, private messages, or data from another shop.
- Preserve the visible UI label, status, validation message, and workflow context that the article explains.
- Prefer a 16:9 WebP export. The catalog may declare another positive aspect ratio when the source UI needs it.

Missing files are intentional during implementation and review: the article UI reserves the declared aspect-ratio space and renders a placeholder with the exact configured path until a redacted screenshot is supplied.
