# Back-office Page Transition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a subtle fade-and-slide-up entry animation to ERP, Shop Owner, and Superadmin page content without changing the customer side.

**Architecture:** Reuse the existing shared layout boundaries. Each back-office layout will wrap only its page-content children in a keyed element using the current Inertia page component, and `resources/css/app.css` will provide one scoped keyframe animation. `AppLayout` will apply the class only for `superAdmin/` pages because it is also used by unrelated legacy pages.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest.

## Global Constraints

- Keep the existing customer-side `CustomerPageTransition` and `scroll-reveal` behavior unchanged.
- Do not add an animation dependency.
- Animate page content only; keep sidebar and header outside the animated wrapper.
- Respect `prefers-reduced-motion`.
- Preserve unrelated working-tree changes and do not commit without an explicit user request.

---

### Task 1: Add the focused transition contract test

**Files:**
- Create: `resources/js/__tests__/backofficePageTransition.test.ts`
- Test: `resources/js/__tests__/backofficePageTransition.test.ts`

**Interfaces:**
- Consumes: the shared layout source files and `resources/css/app.css`.
- Produces: a regression contract proving the transition is scoped to back-office layouts and has the approved motion values.

- [x] **Step 1: Write the focused test**

Create `resources/js/__tests__/backofficePageTransition.test.ts` with:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');
const layoutSources = [
  'resources/js/layout/AppLayout_ERP.tsx',
  'resources/js/layout/AppLayout_shopOwner.tsx',
  'resources/js/layout/CanonicalOwnerLayout.tsx',
].map((path) => readFileSync(resolve(path), 'utf8'));
const superAdminLayout = readFileSync(resolve('resources/js/layout/AppLayout.tsx'), 'utf8');

describe('back-office page transition', () => {
  it('defines the approved subtle fade-and-slide-up motion', () => {
    expect(appCss).toContain('.backoffice-page-enter {');
    expect(appCss).toContain('animation: backoffice-page-enter 350ms');
    expect(appCss).toContain('@keyframes backoffice-page-enter');
    expect(appCss).toContain('transform: translate3d(0, 12px, 0);');
    expect(appCss).toContain('transform: translate3d(0, 0, 0);');
    expect(appCss).toContain('opacity: 0;');
    expect(appCss).toContain('opacity: 1;');
    expect(appCss).toContain('@media (prefers-reduced-motion: reduce)');
    expect(appCss).toMatch(/\.backoffice-page-enter\s*\{\s*animation:\s*none;/);
  });

  it('keys page content in every back-office layout', () => {
    for (const source of layoutSources) {
      expect(source).toContain('key={page.component}');
      expect(source).toContain('className="backoffice-page-enter"');
    }
  });

  it('limits the legacy shared layout transition to Superadmin pages', () => {
    expect(superAdminLayout).toContain('page.component.startsWith("superAdmin/")');
    expect(superAdminLayout).toContain('key={page.component}');
    expect(superAdminLayout).toContain('backoffice-page-enter');
  });

  it('does not alter the customer transition contract', () => {
    expect(appCss).toContain('.customer-page-transition {');
    expect(appCss).toContain('.scroll-reveal {');
  });
});
```

- [x] **Step 2: Run the focused test and verify it fails for the missing implementation**

Run:

```text
pnpm exec vitest run resources/js/__tests__/backofficePageTransition.test.ts
```

Expected: FAIL because the new class, keyframes, and layout wrappers do not exist yet.

### Task 2: Add the scoped CSS animation

**Files:**
- Modify: `resources/css/app.css` near the existing shared entrance-motion rules.

**Interfaces:**
- Consumes: the `backoffice-page-enter` class emitted by the four shared layouts.
- Produces: a 350ms fade-and-12px-slide-up entry animation with a reduced-motion fallback.

- [x] **Step 1: Add the minimal CSS block**

Insert this block before the existing customer-side `.scroll-reveal` rules in `resources/css/app.css`:

```css
/* Subtle page entry for ERP, Shop Owner, and Superadmin content only. */
.backoffice-page-enter {
  animation: backoffice-page-enter 350ms cubic-bezier(.22, 1, .36, 1) both;
}

@keyframes backoffice-page-enter {
  from {
    opacity: 0;
    transform: translate3d(0, 12px, 0);
  }

  to {
    opacity: 1;
    transform: translate3d(0, 0, 0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .backoffice-page-enter {
    animation: none;
  }
}

```

- [x] **Step 2: Run the focused test and verify the CSS assertions still identify the missing layout wrappers**

Run:

```text
pnpm exec vitest run resources/js/__tests__/backofficePageTransition.test.ts
```

Expected: FAIL only on layout-wrapper assertions until Task 3 is complete.

### Task 3: Wrap only back-office page content in the keyed entry animation

**Files:**
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Modify: `resources/js/layout/AppLayout_shopOwner.tsx`
- Modify: `resources/js/layout/CanonicalOwnerLayout.tsx`
- Modify: `resources/js/layout/AppLayout.tsx`

**Interfaces:**
- Consumes: each layout's current Inertia page component from `usePage()`.
- Produces: a fresh `backoffice-page-enter` element when the page component changes, while query-only updates such as filters preserve the existing content element; sidebar/header remain outside that element.

- [x] **Step 1: Update the ERP layout content wrapper**

In `resources/js/layout/AppLayout_ERP.tsx`, add the page hook inside `LayoutContent` and wrap its existing `{children}` only:

```tsx
const LayoutContent: React.FC<{ children: ReactNode; hideHeader?: boolean; fullBleed?: boolean }> = ({ children, hideHeader, fullBleed }) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();
  const page = usePage();

  return (
    <div className="erp-theme min-h-screen xl:flex bg-white text-gray-900 dark:bg-gray-950 dark:text-gray-100">
      <div>
        <AppSidebar_ERP />
        <Backdrop />
      </div>
      <div
        className={`flex-1 transition-all duration-300 ease-in-out bg-white dark:bg-gray-950 ${
          isExpanded || isHovered ? "xl:ml-[290px]" : "xl:ml-[90px]"
        } ${isMobileOpen ? "ml-0" : ""}`}
      >
        {!hideHeader && <AppHeader_ERP />}
        <div className={fullBleed ? "p-0 m-0 max-w-none" : "p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"}>
          <div key={page.component} className="backoffice-page-enter">
            {children}
          </div>
        </div>
      </div>
    </div>
  );
};
```

- [x] **Step 2: Update the Shop Owner layout content wrapper**

In `resources/js/layout/AppLayout_shopOwner.tsx`, add `const page = usePage();` inside `LayoutContent` and replace its direct `{children}` with:

```tsx
        <div className={fullBleed ? "p-0 m-0 max-w-none" : "p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"}>
          <div key={page.component} className="backoffice-page-enter">
            {children}
          </div>
        </div>
```

- [x] **Step 3: Update the canonical owner layout content wrapper**

In `resources/js/layout/CanonicalOwnerLayout.tsx`, import `usePage` and add `const page = usePage();` inside `CanonicalOwnerLayoutContent`. Keep the header outside the existing `<main>`, and replace the direct main children with:

```tsx
        <main className={fullBleed ? "p-0 m-0 max-w-none" : "p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"}>
          <div key={page.component} className="backoffice-page-enter">
            {children}
          </div>
        </main>
```

- [x] **Step 4: Scope the legacy shared layout to Superadmin pages**

In `resources/js/layout/AppLayout.tsx`, import `usePage`, add `const page = usePage();` inside `LayoutContent`, and define:

```tsx
  const isSuperAdminPage = typeof page.component === "string" && page.component.startsWith("superAdmin/");
```

Keep the sidebar/header outside the wrapper and replace the direct children in the padded content container with:

```tsx
        <div className="p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6">
          <div key={page.component} className={isSuperAdminPage ? "backoffice-page-enter" : undefined}>
            {children}
          </div>
        </div>
```

- [x] **Step 5: Run the focused test and verify it passes**

Run:

```text
pnpm exec vitest run resources/js/__tests__/backofficePageTransition.test.ts
```

Expected: PASS with all transition contract tests passing.

### Task 4: Run the required verification and inspect scope

**Files:**
- Inspect: `resources/js/layout/AppLayout.tsx`
- Inspect: `resources/js/layout/AppLayout_ERP.tsx`
- Inspect: `resources/js/layout/AppLayout_shopOwner.tsx`
- Inspect: `resources/js/layout/CanonicalOwnerLayout.tsx`
- Inspect: `resources/css/app.css`
- Inspect: `resources/js/__tests__/backofficePageTransition.test.ts`

**Interfaces:**
- Consumes: the implementation from Tasks 1–3.
- Produces: fresh test/build/diff evidence and confirmation that customer-side transition code was not changed.

- [x] **Step 1: Run the complete frontend test suite**

Run:

```text
pnpm run test:frontend
```

Expected: exit code 0 with no failed tests.

- [x] **Step 2: Run the production frontend build**

Run:

```text
pnpm run build
```

Expected: exit code 0 and Vite emits the production assets. Do not add generated `public/build` output to the task scope if it is untracked user work.

- [x] **Step 3: Inspect the final working-tree scope and whitespace**

Run:

```text
git status --short
git diff --stat
git diff -- resources/css/app.css resources/js/layout/AppLayout.tsx resources/js/layout/AppLayout_ERP.tsx resources/js/layout/AppLayout_shopOwner.tsx resources/js/layout/CanonicalOwnerLayout.tsx resources/js/__tests__/backofficePageTransition.test.ts
git diff --check
```

Expected: only the requested CSS/layout/test/spec/plan files are attributable to this change; existing untracked assets and temporary files remain untouched, and `git diff --check` reports no whitespace errors.

- [x] **Step 4: Do not commit**

Leave the working tree ready for the user to review. The repository operating model requires an explicit request before creating a commit.
