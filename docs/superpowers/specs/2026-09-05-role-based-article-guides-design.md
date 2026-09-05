# Role-Based ERP Article Guides

**Status:** Ready for user review  
**Date:** 2026-09-05

## Goal

Turn the ERP Articles feature into a text-first help center with a separate catalog for each account type. A signed-in user must see only the guides for the account and business context that they are currently using. The existing regular Staff catalog remains Staff-only.

The guide content will use simple English and Tagalog. Every guide will explain the full task in numbered steps instead of depending on screenshots.

## Audiences and boundaries

The first release covers these separate catalogs:

- Regular Staff
- Manager
- Finance, including Finance Staff and Finance Manager where the existing access rules allow it
- HR
- CRM
- Cashier
- Repairer
- Inventory
- Procurement
- Logistics Dispatcher
- Shop Owner

Shop Owner content is filtered by both `registration_type` (`company` or `individual`) and `business_type` (`retail`, `repair`, or `both`). Logistics Rider is not included because it was not part of the requested account list.

The server will resolve the current article audience before rendering the page. Each audience has its own route, access check, catalog namespace, and sidebar item. A detail URL from another audience must not return that audience's article. The browser will load only the catalog chunk for the current audience, so client-side filtering cannot mix unrelated catalogs.

## Page and sidebar design

The shared article shell will remain reusable, but the data and route are audience-specific:

- Staff keeps `/erp/articles` and its existing regular-Staff access boundary.
- Other audiences receive their own article path and route name under their account area.
- The Articles link is the last item in the relevant account sidebar/group. Only the link for the current account audience is shown.
- The language toggle contains only `English` and `Tagalog`; the language icon is removed.

Article details become a single-column, text-first layout. Screenshots, screenshot holders, lightbox behavior, screenshot metadata, and all 94 WebP assets are removed. Steps will have enough explanation to stand alone, with short paragraphs, numbered actions, labels to look for, and what to do when the result is different.

In light mode, ordinary article surfaces use a white background. Outcome cards use green only for a successful result and red only for a rejected or failed result. Neutral and warning outcomes use white backgrounds with neutral borders. Existing text contrast, focus states, and dark-mode readability must remain accessible.

## Content contract

Every article in every catalog will include bilingual versions of:

1. A clear task title and the question it answers.
2. A short purpose statement using common workplace words.
3. What the user needs before starting.
4. The exact page or menu to open.
5. Complete numbered steps from opening the page through saving, submitting, approving, rejecting, or finishing the task.
6. The labels, statuses, fields, and buttons the user should check.
7. What happens after success, rejection, or a pending result, including who handles the next step.
8. Common errors and a safe recovery action.
9. Related guides in the same audience catalog.

Guides will be based on existing ERP routes, pages, permissions, role rules, and tests. They will not invent buttons or workflows that the application does not provide. Domain status labels such as `Pending`, `Processing`, `Under Review`, and `Submit` remain exact when users need to find them on screen.

The catalog checklist for each audience is complete only when it covers the pages and main actions visible to that audience's sidebar, including the relevant business-type or registration-type variants for Shop Owner.

## Technical shape

- Replace the Staff-only article assumptions with a shared article contract and separate audience catalog modules.
- Keep catalog content isolated by audience and use an audience-aware page loader so only the active catalog is requested by the browser.
- Reuse the existing hub, detail, search, language, and related-article patterns where they still fit; do not duplicate the visual shell for every account.
- Remove screenshot-only types, state, imports, components, tests, and asset validation.
- Move or extend the current Articles route/controller access logic without weakening existing permission, role, shop, business-type, or forced-password-change checks.
- Keep generated `public/build` fresh after the final rebase and source changes.

## Verification and acceptance

The implementation is ready when:

- Regular Staff sees only the 32 Staff guides and no other audience content.
- Each requested account can open only its own catalog when its existing role/permission/business context allows it.
- Cross-audience sidebar links and detail URLs are denied or redirected safely.
- Shop Owner variants show the correct guides for company/individual and retail/repair/both contexts.
- Every displayed guide has complete English and Tagalog step content and no screenshot holder.
- Only green/red outcome backgrounds remain; other article surfaces are white in light mode.
- The language icon is absent and both language buttons remain keyboard accessible.
- Sidebar Articles is last in each applicable account menu.
- Focused catalog, page, route/access, and sidebar tests pass, followed by the full frontend suite, relevant Laravel tests, `git diff --check`, and a fresh production build.

## Out of scope

- Changing ERP business logic, permissions, role definitions, or transaction behavior beyond the minimum route/access wiring needed for the separate article pages.
- Adding guides for accounts not listed above, including Logistics Rider.
- Creating new screenshots, illustrations, or external media.
- Creating the Pull Request; the user will create it after the branch is pushed.
