# Per-account ERP command search design

## Status

Approved direction from the user on 2026-09-06. This is a UI-only change; the user will create the PR after the feature branch is pushed.

## Goal

Turn the existing ERP navbar field (`Search or type command...`) into a useful, account-scoped search for pages, articles, and process guides. Remove the duplicate search field from the Articles hub.

The search must stay inside the signed-in account's current ERP scope. A Staff account sees Staff pages and Staff guides; a Manager account sees Manager pages and Manager guides; and so on. Results from another role or account scope must not appear.

## Scope

### Included

- Reuse the existing ERP navbar search field.
- Show a dropdown while the field is focused and has a query.
- Search only the current account/audience scope.
- Include accessible navigation pages already represented by that account's ERP sidebar.
- Include accessible articles and process guides from that account's article catalog.
- Search article title, question, summary, audience, keywords, and guide text.
- Treat `article`, `articles`, `guide`, and `guides` as useful article-type terms so a query such as `article` can surface `Staff pages and access`.
- Prioritize recommended guides, exact title matches, and title/keyword matches.
- Show result type and context so a user can distinguish a page from an article.
- Navigate safely to the selected page or article; do not execute mutating actions from the search dropdown.
- Support mouse selection and keyboard navigation (Arrow Up/Down, Enter, and Escape), plus the existing Ctrl/Cmd+K focus shortcut.
- Keep the interaction usable on the responsive ERP header, including a compact mobile search entry point if the existing header hides the desktop field there.
- Remove the Articles hub's inline text search UI and its duplicate search state. Existing category browsing remains available.

### Excluded

- Searching live order, customer, inventory, payroll, or other database records by ID/name.
- New Laravel controllers, routes, APIs, queries, or authorization rules.
- Actions such as approve, delete, dispatch, refund, submit, or update from the search field.
- Cross-role/global article results.
- Changes to sidebar access behavior.

Live record search can be a later phase with a dedicated, authorization-aware backend endpoint.

## Account-scoped behavior

The search index must resolve the same active account/audience used by the ERP shell and article catalog:

- Staff: Staff dashboard, attendance, retail job orders, product/inventory tools, payslips, and Staff articles that the account can access.
- Manager: Manager dashboard, job orders, repair jobs, inventory/workload/approval pages, and Manager articles that the account can access.
- HR, Finance, CRM, Cashier, Repairer, Inventory, Procurement, Logistics, and Shop Owner: only the pages and article catalog belonging to that scope and permitted for the current account.

The implementation must derive or reuse existing permission, role, module, and article-access checks. It must not create a second permissive access list that can expose links unavailable to the account.

## Dropdown interaction

- Empty or unfocused field: no result list is shown.
- Focus with no query: show a small scoped prompt or the most useful scoped destinations, without loading unrelated roles.
- Query: show up to a compact number of grouped results, with Pages and Articles/Guides sections when both have matches.
- `article` or `guide`: show article results in the active scope, with recommended articles first.
- No match: show a clear scoped empty state and keep the typed query in the field.
- Arrow keys move an active option; Enter opens it; Escape closes the list and returns focus to the field.
- Clicking outside closes the list.
- A selected link must use the current audience's existing path/route and preserve normal Inertia navigation.

## Ranking and presentation

Rank within the current scope in this order:

1. Exact title match.
2. Title starts with or contains the query.
3. Keyword or page-label match.
4. Question, summary, or process-guide text match.
5. Recommended articles before non-recommended articles when relevance is otherwise equal.

Each result should display:

- a neutral type icon;
- the result title;
- a short scope/category label such as `Staff page` or `Staff article`;
- an optional concise supporting line, without exposing sensitive record data.

## Acceptance criteria

1. The Articles hub no longer renders its inline `Search by task, status, or question` input.
2. On the Staff scope, typing `article` in the navbar field shows `Staff pages and access` as an article suggestion and selecting it opens `/erp/articles/staff-workspace-permissions`.
3. Staff searches return Staff pages/articles/process guides only; they do not show Manager, HR, Finance, or other account-scope results.
4. The same scope boundary works for every supported ERP account audience.
5. Suggestions are limited to pages/articles accessible to the current account according to existing frontend access data and catalog filtering.
6. Keyboard navigation, Escape, outside-click closing, and Ctrl/Cmd+K focus work without breaking the existing header menu or account controls.
7. Category browsing and direct article links still work after the Articles inline search is removed.
8. No backend files, database behavior, or mutating actions change.
9. Focused frontend tests, the full frontend suite, `git diff --check`, and a fresh Vite build pass.

## Risks and safeguards

- **Access drift:** reuse existing account/audience and article-access helpers; cover Staff plus representative role scopes in tests.
- **Header regressions:** keep the search behavior isolated from application-menu state and preserve existing focus/escape handling.
- **Large dropdowns:** cap visible results and avoid loading unrelated catalogs until the active scope is known.
- **False promise of record search:** label page/process results clearly; leave live record lookup out of this UI-only change.
