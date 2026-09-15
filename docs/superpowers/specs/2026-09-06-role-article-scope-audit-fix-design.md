# Role-based article scope audit and access fix

## Goal

Make every Help/Articles catalog a reflection of the authenticated account's
real role, permissions, and (for Shop Owners) registration/business
configuration. A direct article URL must not bypass the same scope rules used
by the article hub.

## Findings

- The Shop Owner article middleware rejects the legacy stored value
  `both (retail & repair)`, which explains the 403 shown for real accounts.
- The React page bypasses `getAccessibleArticles` for every employee audience,
  so role catalogs can display articles whose feature permission is missing.
- Several catalogs reference retired routes or permissions, including Finance
  audit logs, HR audit logs, logistics delivery/settings permissions, Repairer
  POS, and owner pages that the route contract marks as denied.
- The article controller accepts any slug under an otherwise valid audience,
  so a cross-role or restricted direct URL can render the article shell.

## Design

1. Reuse `BusinessAccessControlService::normalizeBusinessType` in the owner
   article gate and repair capability check. Keep approval and registration
   checks unchanged; all six approved owner combinations must open the hub.
2. Make each catalog article use the permission(s) for the concrete page it
   documents. Remove articles for features that the role/configuration cannot
   access; do not add application features to make an article valid.
3. Apply `getAccessibleArticles` for every catalog in React, including Staff
   and specialized employee audiences. Normalize owner business aliases in the
   shared viewer utility.
4. Add a small server-side article access registry containing the catalog slug
   boundary and required access metadata. The article controller will return
   404 for unknown, cross-audience, or currently restricted slugs before
   rendering Inertia. This is a defense-in-depth check; the client still
   filters hub/search/category/related links.
5. Keep the existing page/component design and dynamic catalog loading. No
   route, permission, or business module is added solely for documentation.

## Verification

- Feature tests cover all six approved Shop Owner combinations, the legacy
  `both (retail & repair)` value, pending-owner denial, every employee
  audience gate, and restricted/cross-audience direct slugs.
- Frontend tests cover per-permission filtering for employee catalogs,
  business/registration filtering for owners, removed invalid articles, and
  no hidden article reachable through search or related links.
- Run focused PHP/Vitest tests, `pnpm run build`, and `git diff --check`.
