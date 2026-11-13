# Role article scope audit and access fix

> Execute this plan sequentially in the current workspace. Preserve unrelated
> working-tree changes.

## Contract

- Approved Shop Owner accounts can open Articles for company/individual ×
  retail/repair/both when status is approved, including the legacy stored
  `both (retail & repair)` value.
- Each role sees only guides for pages and permissions it actually has.
- Finance and any other role do not receive guides for unavailable/retired
  features; no new system feature is introduced for documentation.
- Hub filtering, category/search/related links, and direct article URLs all
  enforce the same scope. Restricted or cross-audience direct slugs return
  404.

## File-level plan

1. **Add failing authorization tests first.** Extend the article route feature
   tests for the six owner variants, the legacy business alias, restricted and
   cross-audience direct slugs, and invalid role/permission combinations.
2. **Add failing frontend tests.** Update catalog/utility/page tests to require
   Finance/HR invalid articles to be absent, exact feature permissions to drive
   employee visibility, and all audiences to use the shared filter.
3. **Fix server gates.** Reuse the existing business normalization service,
   correct the employee audience permission matrix, and add the minimal
   server-side slug/access registry used by article controllers.
4. **Audit and correct catalogs.** Remove unavailable guides and align every
   remaining article's access metadata and source route/permission references
   with the actual routes, role seeder, and owner module contract. Apply owner
   company/individual and retail/repair/both constraints.
5. **Fix the shared client flow.** Filter all audiences through
   `getAccessibleArticles`, keep owner alias normalization, and ensure detail
   navigation only uses accessible articles.
6. **Run review and verification.** Perform the required simplify, standards,
   spec, security, and dead-code checks; run focused PHP/Vitest tests, build,
   and diff hygiene. Record durable lessons only if they generalize.

## Risks and constraints

- Do not touch unrelated modified files already in the working tree.
- Do not change routes, permissions, or module behavior to support an article.
- Reuse existing middleware/services and the existing article components.
- Treat a missing or restricted direct slug as not found, not as a client-only
  unavailable shell.
