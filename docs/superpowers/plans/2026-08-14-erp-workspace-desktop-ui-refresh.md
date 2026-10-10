# ERP Workspace Desktop UI Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the shop-owner ERP workspace feel more polished on desktop, make its sidebar entry reliably active, and leave one well-placed portal return action without changing mobile behavior or server contracts.

**Architecture:** Keep all module data, URLs, authorization, and navigation visibility server-driven. Fix active matching at the shop-owner sidebar path boundary by reusing the existing `normalizePath` helper, remove only the owner portal link from the shared ERP header, and refine the existing workspace markup with desktop-only Tailwind variants. No new components, dependencies, routes, or backend behavior are needed.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, pnpm.

## Global Constraints

- Apply the visual and interaction changes at the desktop `lg` breakpoint and above; preserve the existing mobile layout, behavior, and flow.
- Keep `/shop-owner/erp/workspace` and all module URLs server-provided; do not duplicate or infer authorization in the frontend.
- Render exactly one **Back to Shop Owner Portal** action in the ERP workspace, using `props.urls.portal`.
- Preserve owner/employee sidebar filtering, ERP module scoping, notification selection, profile/logout behavior, and all unrelated working-tree changes.
- Reuse existing Tailwind utilities, brand tokens, icons, and Inertia links; add no dependency or speculative abstraction.
- Do not edit `.env`, generated `public/build`, `vendor/`, `node_modules/`, or the unrelated logistics changes.

---

## File Map

- Modify `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx` to cover normalized workspace matching and accessible active state.
- Modify `resources/js/layout/__tests__/AppHeader_ERP.test.tsx` to prove the duplicate navbar action is gone while owner controls remain.
- Modify `resources/js/Pages/ERP/__tests__/Workspace.test.tsx` to assert the single server-provided portal action.
- Modify `resources/js/layout/AppSidebar_shopOwner.tsx` to normalize path comparisons, expose `aria-current`, and strengthen the desktop active rail.
- Modify `resources/js/layout/AppHeader_ERP.tsx` to remove the navbar portal link only.
- Modify `resources/js/Pages/ERP/Workspace.tsx` to apply the approved desktop-only visual hierarchy and interaction polish.
- Keep `docs/superpowers/specs/2026-08-14-erp-workspace-desktop-ui-refresh-design.md` as the design source of truth.

## Interfaces

- `normalizePath(value: string): string` remains the shared local path normalizer in `AppSidebar_shopOwner.tsx`; `isPathActive(path?: string)` will compare normalized current and target paths.
- `Workspace` continues to consume `WorkspaceProps` exactly as currently defined: `enabledModules`, `unavailableModules`, and server-provided `urls`.
- `AppHeader_ERP` continues to consume `auth.erpActor` and `erpUrls` for owner identity, notifications, profile, and logout; only the portal-return JSX is removed.

---

### Task 1: Add regression coverage for desktop workspace navigation

**Files:**
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Modify: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`
- Modify: `resources/js/Pages/ERP/__tests__/Workspace.test.tsx`

- [x] **Step 1: Update the sidebar test to exercise a normalized URL and accessible active state**

In the existing `shows core-only navigation in the owner ERP picker` test, change the fixture URL to include both a trailing slash and query string, then add the `aria-current` assertion:

```tsx
state.url = '/shop-owner/erp/workspace/?tab=modules';

expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveClass('menu-item-active');
expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveAttribute('aria-current', 'page');
```

- [x] **Step 2: Update the header test to require the navbar action to be absent**

Replace the existing owner-mode portal-link assertion in `AppHeader_ERP.test.tsx` with:

```tsx
expect(screen.queryByRole('link', { name: /back to shop owner portal/i })).not.toBeInTheDocument();
```

Keep the owner identity, Owner mode, notification base path, profile URL, and logout URL assertions unchanged.

- [x] **Step 3: Add a single-return-action assertion to the workspace test**

Append this assertion to the first workspace test:

```tsx
expect(screen.getAllByRole('link', { name: /back to shop owner portal/i })).toHaveLength(1);
expect(screen.getByRole('link', { name: /back to shop owner portal/i })).toHaveAttribute(
  'href',
  '/shop-owner/dashboard',
);
```

- [x] **Step 4: Run the focused tests and verify the new assertions fail for the current implementation**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/__tests__/Workspace.test.tsx
```

Expected: the sidebar normalization/`aria-current` assertion and the header absence assertion fail against the current implementation; the existing workspace data/link assertions remain green.

---

### Task 2: Fix the shop-owner ERP Workspace active state

**Files:**
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`

- [x] **Step 1: Normalize path-based active matching**

Replace the current raw-string `isPathActive` callback with:

```tsx
const isPathActive = useCallback(
  (path?: string) => {
    if (!path) return false;

    const currentPath = normalizePath(url);
    const targetPath = normalizePath(path);

    return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`);
  },
  [url]
);
```

This preserves nested-path matching while making query strings, origins, and trailing slashes irrelevant.

- [x] **Step 2: Add an accessible, stronger desktop active treatment to path links**

In the non-route `Link` branch of `renderMenuItems`, compute one `active` value and use it for the link class, icon class, and `aria-current`:

```tsx
const active = nav.route ? isActive(nav.route) : isPathActive(nav.path);

<Link
  href={getHref(nav.route, nav.path) || "#"}
  prefetch={SIDEBAR_PREFETCH}
  cacheFor={SIDEBAR_PREFETCH_CACHE}
  aria-current={active ? "page" : undefined}
  className={`menu-item group ${active
    ? "menu-item-active lg:border-l-2 lg:border-brand-500 lg:shadow-theme-sm"
    : "menu-item-inactive lg:border-l-2 lg:border-transparent"
  }`}
>
  <span
    className={`menu-item-icon-size w-6 h-6 ${active
      ? "menu-item-icon-active"
      : "menu-item-icon-inactive"
    }`}
  >
    {nav.icon}
  </span>
  {(isExpanded || isHovered || isMobileOpen) && (
    <span className="menu-item-text">{nav.name}</span>
  )}
</Link>
```

The desktop border and shadow are additive to the existing active utility; mobile keeps the existing `menu-item-active`/`menu-item-inactive` classes and flow.

- [x] **Step 3: Run the focused sidebar test**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: all ERP sidebar tests pass, including the query/trailing-slash active-state assertion, with no change to owner module filtering tests.

---

### Task 3: Remove the duplicate navbar portal action

**Files:**
- Modify: `resources/js/layout/AppHeader_ERP.tsx`

- [x] **Step 1: Delete only the owner portal `Link` block**

Remove the conditional block that renders `erpUrls.portal` and the `Back to Shop Owner Portal` text. Leave the surrounding Owner mode identity block, `NotificationBell`, `ThemeToggleButton`, and dropdown rendering unchanged.

- [x] **Step 2: Run the header and workspace tests**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/__tests__/Workspace.test.tsx
```

Expected: owner mode keeps identity, notifications, profile, and logout behavior; the header has no portal-return link; the workspace still has exactly one portal-return link.

---

### Task 4: Apply the approved desktop-only workspace polish

**Files:**
- Modify: `resources/js/Pages/ERP/Workspace.tsx`

- [x] **Step 1: Preserve the existing props and module-card link contract**

Keep `ModuleSummary`, `WorkspaceProps`, `props.urls`, `enabledModules`, `unavailableModules`, and every module URL/reason untouched. Do not add client-side authorization or new data fetching.

- [x] **Step 2: Refine the desktop hero without changing mobile flow**

Keep the current base/mobile classes and add desktop variants to the existing sections:

```tsx
<main className="mx-auto max-w-6xl space-y-8 lg:max-w-7xl lg:space-y-10" ...>
<section className="... lg:rounded-3xl lg:p-10 lg:shadow-theme-sm" ...>
  <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
    ...
    <div className="flex flex-wrap items-center gap-3 lg:pt-1">
      ...
      <Link className="... lg:min-w-[13.5rem] lg:whitespace-nowrap" ...>
```

Use the existing owner badge and server-provided portal link; only the desktop alignment, scale, and surface treatment change.

- [x] **Step 3: Refine available/unavailable module hierarchy and card interaction states**

Add desktop-only variants to the existing headings, grids, cards, glyphs, status badges, empty states, and manage button. The resulting classes must keep the current mobile base classes and use the existing blue/gray/dark tokens. The available card remains an Inertia `Link`; the unavailable card remains a non-interactive `article`.

Use these concrete changes:

```tsx
<section ... className="space-y-4 lg:space-y-5">
<h2 ... className="text-xl ... lg:text-2xl">
<div className="grid gap-4 md:grid-cols-2 lg:gap-5">
<Link className="group ... lg:min-h-40 lg:rounded-2xl lg:p-6 lg:shadow-theme-sm ...">
<article className="... lg:min-h-40 lg:rounded-2xl lg:p-6 ...">
<span className="... lg:h-11 lg:w-11 lg:rounded-2xl">
```

Add `lg:shadow-theme-sm` to the primary **Manage modules** button and `lg:p-6` to the empty states. Keep the existing hover lift, focus ring, dark-mode classes, labels, and reasons.

- [x] **Step 4: Run the workspace test**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/__tests__/Workspace.test.tsx
```

Expected: all workspace content, server-provided links, unavailable reasons, and the single portal-return link pass.

---

### Task 5: Review, simplify, and verify the complete change

**Files:**
- Review only: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Review only: `resources/js/layout/AppHeader_ERP.tsx`
- Review only: `resources/js/Pages/ERP/Workspace.tsx`
- Review only: the three focused test files and the new design/plan docs

- [x] **Step 1: Run the focused frontend suite**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/__tests__/Workspace.test.tsx
```

Expected: exit code 0 with all tests in the three files passing.

- [x] **Step 2: Run the repository frontend test command**

Run:

```powershell
pnpm run test:frontend
```

Expected: exit code 0. Report any pre-existing unrelated failures separately rather than changing unrelated files.

- [x] **Step 3: Build the frontend**

Run:

```powershell
pnpm run build
```

Expected: exit code 0. Do not hand-edit generated build output.

- [x] **Step 4: Run diff hygiene and inspect the changed-file boundary**

Run:

```powershell
git diff --check
git status --short
git diff -- resources/js/layout/AppSidebar_shopOwner.tsx resources/js/layout/AppHeader_ERP.tsx resources/js/Pages/ERP/Workspace.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/__tests__/Workspace.test.tsx docs/superpowers/specs/2026-08-14-erp-workspace-desktop-ui-refresh-design.md docs/superpowers/plans/2026-08-14-erp-workspace-desktop-ui-refresh.md
```

Expected: no whitespace errors; only the requested UI, tests, and planning/spec files are attributable to this task. Existing logistics edits, `package-lock.json`, `.pnpm-store/`, and `DESIGN.md` remain present and untouched.

- [x] **Step 5: Perform the final review checklist before reporting completion**

Confirm each item from the design spec:

```text
[ ] One portal-return link in the workspace, using props.urls.portal.
[ ] Header portal-return link removed.
[ ] Sidebar active for exact, query-string, and trailing-slash workspace URLs.
[ ] Active link has aria-current="page".
[ ] Owner/employee filtering and module scoping unchanged.
[ ] Desktop-only polish uses lg: variants; mobile flow is unchanged.
[ ] Focus, hover, available, unavailable, empty, and manage-module states remain covered.
[ ] Fresh focused tests, frontend tests, build, and diff-check evidence recorded.
```

The sandbox currently denies writes to `.git/index.lock`; if that remains true, do not retry destructive git operations or claim a commit. Report the exact permission error and leave the working tree otherwise intact.
