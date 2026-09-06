# Per-account ERP command search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Make the existing ERP navbar search return only the current account's accessible pages, process guides, and articles, while removing the duplicate search field from the Articles hub.

**Architecture:** Build a small pure search model that resolves one ERP audience from the signed-in account, filters page metadata by the same role/permission inputs used by the ERP shell, and searches only that audience's lazy-loaded article catalog. Render the model through one shared, keyboard-accessible dropdown component used by the employee and canonical shop-owner headers. Keep live record lookup and mutating commands out of this UI-only change.

**Tech Stack:** Laravel/Inertia application shell, React 18, TypeScript 5.7, Tailwind CSS 4, \`@inertiajs/react\` links, existing article catalog/access helpers, Vitest, Testing Library, Vite 7.

## Global Constraints

- UI-only change: do not modify Laravel controllers, routes, APIs, database code, authorization rules, \`.env\`, \`vendor/\`, or \`node_modules/\`.
- Search results must stay inside the signed-in account's active audience; Staff must never receive Manager, HR, Finance, or other audience results.
- Search all accessible articles in the active audience, with recommended articles ranked ahead of other matching articles.
- Page suggestions must be navigation-only; do not add approve, delete, dispatch, refund, submit, or update commands.
- Reuse existing \`getAccessibleArticles\`, article catalog loaders, role/permission inputs, and existing header/sidebar route conventions where possible.
- Add no new dependency; use the installed React, Lucide, Inertia, Vitest, and Testing Library packages.
- Use TDD: each production behavior starts with a focused failing test and the failure is observed before implementation.
- Preserve unrelated working-tree changes; stage only files owned by this task plus the fresh \`public/build/\` output.
- Use local frontend binaries when the pnpm wrapper rejects the project's signed pnpm runtime: \`& .\\node_modules\\.bin\\vitest.cmd ...\` and \`& .\\node_modules\\.bin\\vite.cmd build\`.

---

### Task 1: Add the account-scoped search model and shared article viewer parsing

**Files:**

- Create: \`resources/js/data/erpCommandSearch.ts\`
- Create: \`resources/js/data/__tests__/erpCommandSearch.test.ts\`
- Create: \`resources/js/utils/articleViewer.ts\`
- Create: \`resources/js/utils/__tests__/articleViewer.test.ts\`
- Modify: \`resources/js/utils/articleGuides.ts\`
- Modify: \`resources/js/Pages/ERP/Articles/Index.tsx\`

**Interfaces:**

- Produces \`ErpSearchScope\`, \`ErpSearchViewer\`, \`ErpSearchPage\`, \`ErpSearchResult\`, \`resolveErpSearchScope(url, props)\`, \`readErpSearchViewer(props, scope)\`, \`getAccessibleErpSearchPages(scope, viewer)\`, and \`searchErpCommands({ scope, pages, articles, query, language, basePath })\` from \`resources/js/data/erpCommandSearch.ts\`.
- Produces \`readArticleViewer(props, audience)\` from \`resources/js/utils/articleViewer.ts\`; the Articles page and navbar search use this same parser.
- \`searchErpCommands\` returns typed results with \`kind: "page" | "article"\`, \`label\`, \`href\`, \`scopeLabel\`, \`description\`, and \`recommended\` fields. It returns an empty array for blank queries.

- [ ] **Step 1: Write the failing model tests**

Add tests covering the required contract before adding the model. The Staff fixture must include the same permissions used by the existing Staff Articles test. The tests must prove:

    resolveErpSearchScope("/erp/staff/dashboard", staffProps) === "staff";
    getAccessibleErpSearchPages("staff", staffViewer) includes "Retail Job Orders";
    Staff page results never have a non-Staff scope;
    searching Staff articles for "article" includes the exact result
    "Staff pages and access" at "/erp/articles/staff-workspace-permissions";
    Manager catalog results are not returned from a Staff-scoped search;
    recommended matching articles sort before non-recommended matching articles.

Also add a small \`readArticleViewer\` test that verifies permissions, roles, legacy role, business type, registration type, and owner mode are safely read from a normal Inertia props object while malformed values become empty/null values.

- [ ] **Step 2: Run the model tests and verify they fail for the missing implementation**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/data/__tests__/erpCommandSearch.test.ts resources/js/utils/__tests__/articleViewer.test.ts --reporter=dot

Expected: FAIL because the new search model and viewer parser do not exist yet. If the runner reports an import or syntax error instead of the intended missing behavior, correct the test setup and rerun until the failure is meaningful.

- [ ] **Step 3: Extract the shared article viewer parser**

Create \`readArticleViewer(props, audience)\` with the current Articles page behavior:

    export const readArticleViewer = (props: unknown, audience: string): ArticleViewer => {
      const root = isRecord(props) ? props : {};
      const auth = isRecord(root.auth) ? root.auth : {};
      const user = isRecord(auth.user) ? auth.user : {};
      const shopOwner = isRecord(auth.shop_owner)
        ? auth.shop_owner
        : isRecord(user.shop_owner)
          ? user.shop_owner
          : isRecord(root.shop_owner)
            ? root.shop_owner
            : {};

      return {
        permissions: readStringArray(auth.permissions),
        roles: readStringArray(user.roles),
        legacyRole: typeof user.role === "string" ? user.role : null,
        businessType: typeof shopOwner.business_type === "string" ? shopOwner.business_type : null,
        registrationType: typeof shopOwner.registration_type === "string" ? shopOwner.registration_type : null,
        ownerMode: audience === "shop-owner"
          && isRecord(auth.erpActor)
          && auth.erpActor.type === "shop_owner"
          && auth.erpActor.ownerMode === true,
      };
    };

Use a local record guard and string-array guard rather than casting arbitrary Inertia props. Update \`Index.tsx\` to import this function and delete only its duplicate local \`readStringArray\`/\`readViewer\` implementations.

- [ ] **Step 4: Implement the scoped page registry and search ranking**

In \`erpCommandSearch.ts\`:

1. Normalize roles by trimming, uppercasing, and replacing underscores with spaces, matching the existing ERP sidebar/article access conventions.
2. Resolve the scope in this order: valid \`articleAudience\` prop, canonical shop-owner actor, explicit legacy role, known role in the roles array, then current URL prefix. Recognize \`STAFF\`, \`MANAGER\`, \`FINANCE\`, \`HR\`, \`CRM\`, \`CASHIER\`, \`REPAIRER\`, \`INVENTORY\`, \`INVENTORY MANAGER\`, \`PROCUREMENT\`, \`PROCUREMENT MANAGER\`, \`LOGISTICS DISPATCHER\`, and \`SHOP OWNER\`.
3. Define typed page metadata for the visible page families. Use the exact current paths/section query strings from \`AppSidebar_ERP.tsx\` and \`articleAudience.ts\`:

    Staff: Staff Dashboard, Log Attendance, Retail Job Orders, Product Management,
    Shoe Pricing Requests, Inventory Overview, My Payslips, Staff Articles.

    Manager: Manager Dashboard, Log Attendance, Job Orders, Repair Jobs,
    Inventory Overview, Staff & Workload, Leave Approvals, Suspension Approvals,
    Termination Approvals, Rehire Approvals, Reports, Audit Logs, Manager Articles.

    HR: Dashboard, Employees, View Attendance, Leave Requests, Overtime Requests,
    View Slip, Generate Slip, Salary Changes, HR Articles.

    Finance: Dashboard, Invoices, Repair Pricing Approval, Shoe Pricing Approval,
    Purchase Request Review, Refund Approval, Payslip Approvals, Expenses,
    Finance Articles.

    CRM: CRM Dashboard, Customers, Customer Support, Customer Reviews, CRM Articles.

    Cashier: Cashier Dashboard, Point of Sale, Cashier Articles.

    Repairer: Repair Dashboard, Job Orders Repair, Warranty Queue, Upload Services,
    Repair Pricing Requests, Stocks Overview, Request Material, Chat,
    Repair Reject Approval, Repairer Articles.

    Inventory: Inventory Dashboard, Manage Stock Items, Stock Movement, Stock Requests,
    Material Request Queue, Supplier Orders, Inventory Articles.

    Procurement: Dashboard, Purchase Requests, Stock Request Approval, Purchase Orders,
    Suppliers Management, Procurement Articles.

    Logistics: Logistics Dashboard, Shipments, Batches, My Deliveries, Riders,
    Settings, Logistics Articles.

    Each page entry must have a label, path, searchable aliases, audience, and an
    any-of role/permission gate that mirrors its current sidebar filter. This is a
    suggestion guard only; the server remains the authorization boundary.

4. For \`shop-owner\`, flatten only \`ownerShell.groups\` items with
   \`available === true\`, recursively include available children, and add
   \`/shop-owner/erp/articles\`. Do not include unavailable management links.
5. Filter article input with existing \`getAccessibleArticles\` before searching it.
   Add the type aliases \`article\`, \`articles\`, \`guide\`, and \`guides\` to the
   article search fields in \`articleGuides.ts\`, so an article query intentionally
   returns article guides.
6. Rank exact title, title prefix/contains, keywords/page labels, guide text, and
   recommended status. Keep the result cap in the UI layer, and prioritize article
   results for type-only queries such as \`article\` so \`Staff pages and access\`
   cannot be hidden behind the Articles page link.

- [ ] **Step 5: Run the model tests and verify they pass**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/data/__tests__/erpCommandSearch.test.ts resources/js/utils/__tests__/articleViewer.test.ts --reporter=dot

Expected: all model and parser tests pass, including the Staff article link and Staff/Manager scope boundary.

- [ ] **Step 6: Commit the model layer**

Stage only the model/parser files and commit:

    git add resources/js/data/erpCommandSearch.ts resources/js/data/__tests__/erpCommandSearch.test.ts resources/js/utils/articleViewer.ts resources/js/utils/__tests__/articleViewer.test.ts resources/js/utils/articleGuides.ts resources/js/Pages/ERP/Articles/Index.tsx
    git commit -m "feat: add scoped ERP command search model"

### Task 2: Build and integrate the shared navbar search dropdown

**Files:**

- Create: \`resources/js/components/header/ErpCommandSearch.tsx\`
- Create: \`resources/js/components/header/__tests__/ErpCommandSearch.test.tsx\`
- Modify: \`resources/js/layout/AppHeader_ERP.tsx\`
- Modify: \`resources/js/layout/CanonicalOwnerHeader.tsx\`
- Modify: \`resources/js/layout/__tests__/AppHeader_ERP.test.tsx\`
- Modify: \`resources/js/layout/__tests__/CanonicalOwnerHeader.test.tsx\`

**Interfaces:**

- Consumes the model exports from Task 1 and current \`usePage()\` props.
- Produces one shared \`ErpCommandSearch\` component with optional \`inputRef\`, \`id\`, and \`compact\` presentation props, so both header variants share behavior and accessibility semantics.

- [ ] **Step 1: Write the failing component and header integration tests**

Add tests using the existing Inertia mocks that prove:

    A Staff header with "article" shows an option named
    "Staff pages and access", marked as an article, with href
    "/erp/articles/staff-workspace-permissions".

    A Staff header does not show Manager article/page results when the query is
    "manager dashboard".

    ArrowDown/ArrowUp move the active option, Enter selects it, Escape closes
    the list, and outside pointer down closes it.

    The input exposes combobox/listbox semantics, aria-expanded,
    aria-controls, aria-activedescendant, and a stable option id.

    A canonical owner header includes available owner-shell items but excludes
    unavailable items.

    The existing Ctrl+K/Meta+K focus behavior still works.

- [ ] **Step 2: Run the focused tests and verify they fail for the missing dropdown**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/components/header/__tests__/ErpCommandSearch.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/layout/__tests__/CanonicalOwnerHeader.test.tsx --reporter=dot

Expected: FAIL because the current headers render uncontrolled text inputs without suggestions or combobox semantics.

- [ ] **Step 3: Implement the shared dropdown component**

Create \`ErpCommandSearch.tsx\` with these behaviors:

1. Read \`page.url\` and \`page.props\`, resolve the active scope, parse the viewer, and load only that scope's article catalog with \`loadArticleCatalog(scope)\`.
2. Build accessible page results from the model. For canonical owners, flatten \`ownerShell\`; for employee headers, use the current role/permission scope.
3. Keep local \`query\`, \`isOpen\`, and \`activeIndex\` state. Open on focus/change when the query is non-blank, reset the active index when results change, and close on Escape or outside pointer down.
4. Render a \`form\` with the existing visual search field and shortcut badge. Prevent submit navigation. Use \`role="combobox"\`, \`aria-expanded\`, \`aria-controls\`, \`aria-autocomplete="list"\`, and a \`role="listbox"\` dropdown with \`role="option"\` links.
5. Use neutral existing icon styles and small grouped headings (\`Pages\`, \`Articles\`) without introducing colored account-specific accents. Show a compact loading state while the active catalog is loading, and a scoped no-results state when the query has no matches.
6. Implement ArrowDown/ArrowUp wrapping, Enter selection, and Escape close. Selecting a result closes the dropdown and lets the existing Inertia \`Link\` navigate.
7. Cap visible results at eight, keeping type-only article queries article-first. Do not add localStorage recent searches or live record calls.

- [ ] **Step 4: Replace both navbar inputs with the shared component**

In \`AppHeader_ERP.tsx\`:

- Keep the existing Ctrl/Cmd+K document listener and pass its \`inputRef\` to \`ErpCommandSearch\`.
- Replace only the current desktop form contents; preserve application-menu state, notification controls, account dropdowns, and desktop spacing.
- Add a compact mobile search trigger/panel using the same component, so search is not desktop-only. The mobile panel must close when a result is selected or Escape is pressed.

In \`CanonicalOwnerHeader.tsx\`:

- Preserve the owner header's settings/profile routes and Ctrl/Cmd+K listener.
- Replace the desktop input with the shared component and expose the same compact mobile entry point.

- [ ] **Step 5: Run the focused component/header tests and verify they pass**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/components/header/__tests__/ErpCommandSearch.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/layout/__tests__/CanonicalOwnerHeader.test.tsx --reporter=dot

Expected: all shared search, account-scope, keyboard, outside-click, owner-shell, and existing header tests pass.

- [ ] **Step 6: Commit the shared navbar search**

Stage only the shared component/header files and commit:

    git add resources/js/components/header/ErpCommandSearch.tsx resources/js/components/header/__tests__/ErpCommandSearch.test.tsx resources/js/layout/AppHeader_ERP.tsx resources/js/layout/CanonicalOwnerHeader.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/layout/__tests__/CanonicalOwnerHeader.test.tsx
    git commit -m "feat: add scoped ERP navbar search"

### Task 3: Remove the duplicate Articles hub search without removing article browsing

**Files:**

- Modify: \`resources/js/components/articles/ArticleHub.tsx\`
- Modify: \`resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx\`

**Interfaces:**

- Keeps ArticleHub category filtering, recommended cards, direct article links, language toggle, and URL category state.
- Removes only the inline query input and query-driven filtering/URL state; the navbar owns article search.

- [ ] **Step 1: Update the page tests to express the new behavior**

Change the existing Staff Articles tests before touching \`ArticleHub\`:

- Replace the \`search staff articles\` assertion with an assertion that no hub searchbox named \`Search Staff articles\` is rendered.
- Replace the Tagalog search test with a language-toggle test that confirms Tagalog copy and localStorage persistence without a page search input.
- Replace the query/no-results test with a category-state test: selecting \`Orders & returns\` updates \`category=orders\`, shows that category, and Clear resets the category and URL.
- Replace URL \`q\` hydration with an assertion that legacy \`q\` is not rendered as a page search control and is removed when the hub synchronizes its category URL state.
- Keep tests for Staff/Manager catalogs, invalid slugs, detail links, and access filtering.

- [ ] **Step 2: Run the focused Articles page test and verify it fails**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx --reporter=dot

Expected: FAIL because ArticleHub still renders \`Search by task, status, or question\` and maintains query filtering.

- [ ] **Step 3: Remove only the inline search state and markup**

In \`ArticleHub.tsx\`:

- Remove the \`Search\` icon import and inline label/input block.
- Make \`readHubState\` return only \`{ category }\`, validating it against the loaded catalog.
- Remove \`query\`, \`setQuery\`, \`searchArticles\`, query filtering, and \`q\` URL serialization.
- Keep category filtering, category URL serialization, clear behavior, recommended visibility, and the no-articles state.
- Keep only copy and imports still used by category filtering; leave no unused search placeholder or state variable.

- [ ] **Step 4: Run the focused Articles page test and verify it passes**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx --reporter=dot

Expected: all Articles page tests pass, including category browsing, language selection, account catalogs, and direct links, with no duplicate inline searchbox.

- [ ] **Step 5: Commit the Articles hub cleanup**

Stage only the Articles hub and its test and commit:

    git add resources/js/components/articles/ArticleHub.tsx resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx
    git commit -m "fix: move article search into ERP navbar"

### Task 4: Review, verify, build, and push the UI-only change

**Files:**

- Modify: tracked generated files under \`public/build/\` after the final Vite build.
- Do not stage: existing unrelated modifications to Logistics, HR approvals, \`package-lock.json\`, \`tests/Feature/Logistics/LogisticsPageAccessTest.php\`, \`.pnpm-store/\`, \`.superpowers/brainstorm/staff-articles-20260825/\`, or \`DESIGN.md\`.

- [ ] **Step 1: Perform the sequential review gates**

Review the final diff against the spec and these criteria:

- Simplify/YAGNI: no recent-search storage, live record API, mutating action handlers, or duplicate role catalogs.
- Standards/spec: headers preserve existing controls; search is role/audience scoped; the Staff example link is exact; Articles hub has no inline query input.
- Type/React quality: no new \`any\` for search state, stable effect dependencies for catalog loading, accessible combobox/listbox semantics, and no unused imports after removing ArticleHub search.
- Reuse/dead code: the shared viewer parser is used by both Articles and navbar; existing article access/search helpers are reused; no old query state remains.
- Security/risk: dropdown only navigates to existing links and does not expose live records or mutate data; results are filtered before render.

- [ ] **Step 2: Run focused and full frontend verification**

Run:

    & .\\node_modules\\.bin\\vitest.cmd run resources/js/data/__tests__/erpCommandSearch.test.ts resources/js/utils/__tests__/articleViewer.test.ts resources/js/components/header/__tests__/ErpCommandSearch.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/layout/__tests__/CanonicalOwnerHeader.test.tsx resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx --reporter=dot
    & .\\node_modules\\.bin\\vitest.cmd run --reporter=dot --silent
    git diff --check

Expected: focused and full frontend suites pass with zero failures, and \`git diff --check\` produces no output.

- [ ] **Step 3: Build fresh frontend assets**

Run:

    & .\\node_modules\\.bin\\vite.cmd build

Expected: Vite exits with code 0 and regenerates tracked \`public/build/\` manifest/assets. Inspect \`git status --short\` and \`git diff --stat\` to confirm only intended source/docs/generated files are present.

- [ ] **Step 4: Run the project git workflow checks before integration**

Read \`docs/git-workflow.md\`, then run the required fetch/rebase sequence without force operations:

    git fetch --prune origin
    git rebase --autostash origin/solespace-b

If rebase changes task-owned source, rerun the focused tests, full frontend suite, \`git diff --check\`, and Vite build before pushing. Resolve only conflicts in task-owned files; do not overwrite unrelated working-tree work.

- [ ] **Step 5: Stage the UI change and fresh build only**

Review \`git status --short\`, then stage exact task-owned files and generated build output. Do not use \`git add .\`:

    git add docs/superpowers/plans/2026-09-06-scoped-erp-command-search.md resources/js/data/erpCommandSearch.ts resources/js/data/__tests__/erpCommandSearch.test.ts resources/js/utils/articleViewer.ts resources/js/utils/__tests__/articleViewer.test.ts resources/js/utils/articleGuides.ts resources/js/Pages/ERP/Articles/Index.tsx resources/js/components/header/ErpCommandSearch.tsx resources/js/components/header/__tests__/ErpCommandSearch.test.tsx resources/js/layout/AppHeader_ERP.tsx resources/js/layout/CanonicalOwnerHeader.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/layout/__tests__/CanonicalOwnerHeader.test.tsx resources/js/components/articles/ArticleHub.tsx resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx public/build
    git diff --cached --check

- [ ] **Step 6: Commit and push the implementation branch**

Commit the staged implementation and push the existing feature branch without force:

    git commit -m "feat: add account-scoped ERP command search"
    git push origin feature/monochrome-erp-theme-clean

Confirm the push output and final \`git status --short\`. The final report must distinguish task-owned commits/files from unrelated pre-existing working-tree changes and list every fresh verification command with its observed result.

