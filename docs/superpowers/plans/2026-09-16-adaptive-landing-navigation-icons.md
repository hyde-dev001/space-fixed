# Adaptive landing navigation icon colors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the landing page hamburger, search, cart, notification bell, and message icons readable over changing backgrounds without changing the transparent header layout.

**Architecture:** The shared `Navigation` component will keep a white source color for landing-page SVG artwork and apply `mix-blend-difference` to the SVGs only, allowing the browser to produce a contrasting light/dark result from the pixels behind each icon. `NotificationBell` will receive an optional `iconClassName` so its Bell SVG can use the same blend class while its unread badge remains unblended. Existing non-landing colors and interactions stay unchanged.

**Tech Stack:** React 18, TypeScript 5.7, Inertia React, Tailwind CSS 4, Vitest, Vite.

## Global Constraints

- Reuse the existing shared `Navigation` and `NotificationBell` components.
- Do not add dependencies or change the header background, layout, menu, search, cart, or badge behavior.
- Apply adaptive blending only when `isLandingPage && landingSidebar` is true.
- Preserve unrelated working-tree changes and do not commit unless the user explicitly requests it.
- Verify the focused navigation contract test, the production build, and the landing page in a browser.

---

### Task 1: Add the adaptive-icon regression contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`

**Interfaces:**
- Consumes: the source text of `Navigation.tsx` and `NotificationBell.tsx`.
- Produces: a failing contract that requires the shared adaptive SVG class and the optional notification icon class.

- [ ] **Step 1: Extend the test fixture with `NotificationBell.tsx` source**

Add this fixture beside `appCssSource`:

```ts
const notificationBellSource = readFileSync(
  resolve('resources/js/components/common/NotificationBell.tsx'),
  'utf8',
);
```

- [ ] **Step 2: Write the failing adaptive-icon contract**

Add this test after the existing logo/search scrollbar contract:

```ts
  it('uses pixel-adaptive blending for landing navigation artwork only', () => {
    expect(navigationSource).toContain(
      "const adaptiveLandingIconClass = isLandingPage && landingSidebar ? 'mix-blend-difference' : '';",
    );
    expect(navigationSource).toContain(
      'const headerIconSvgClasses = `block h-6 w-6 shrink-0 ${adaptiveLandingIconClass}`;',
    );
    expect(navigationSource).toContain('iconClassName={adaptiveLandingIconClass}');
    expect(notificationBellSource).toContain('iconClassName?: string;');
    expect(notificationBellSource).toContain(
      'className={`block h-5 w-5 shrink-0 ${iconClassName}`}',
    );
  });
```

- [ ] **Step 3: Run the contract to verify it fails before implementation**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
```

Expected: FAIL in the new adaptive-icon test because the implementation does not yet define `adaptiveLandingIconClass` or `iconClassName`.

### Task 2: Apply the minimum shared SVG styling change

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx:366-374, 778-785, 1193-1200`
- Modify: `resources/js/components/common/NotificationBell.tsx:9-40`

**Interfaces:**
- Consumes: the regression contract from Task 1.
- Produces: `adaptiveLandingIconClass`, shared by every landing navigation SVG and by `NotificationBell`'s Bell SVG.

- [ ] **Step 1: Define the landing-only adaptive class and keep the SVG source white**

Replace the existing header icon class declarations with:

```tsx
  const isAdaptiveLandingNav = isLandingPage && landingSidebar;
  const adaptiveLandingIconClass = isAdaptiveLandingNav ? 'mix-blend-difference' : '';
  const headerIconButtonClasses = `relative inline-flex h-10 w-10 shrink-0 items-center justify-center p-0 leading-none transition-all ${
    isAdaptiveLandingNav
      ? 'text-white hover:opacity-70'
      : isTransparentNav
        ? 'text-white hover:opacity-70'
        : 'text-gray-900 rounded-full hover:bg-gray-100 hover:opacity-100'
  }`;
  const headerIconSvgClasses = `block h-6 w-6 shrink-0 ${adaptiveLandingIconClass}`;
```

- [ ] **Step 2: Use the adaptive SVG class for the hamburger and shared icons**

Keep each existing icon path and behavior, but render the hamburger with `className={headerIconSvgClasses}` and keep its button source color white when `isAdaptiveLandingNav` is true. The existing search, cart, and message SVGs already use `headerIconSvgClasses`, so the shared class will cover them without changing their markup or badges.

- [ ] **Step 3: Add the optional icon-only prop to `NotificationBell`**

Update the prop interface, destructuring, and Bell render as follows:

```tsx
interface NotificationBellProps {
  basePath?: string;
  className?: string;
  iconSize?: number;
  badgeClassName?: string;
  iconClassName?: string;
}

const NotificationBell: React.FC<NotificationBellProps> = ({
  basePath = '/api/notifications',
  className = '',
  iconSize = 24,
  badgeClassName = '',
  iconClassName = '',
}) => {
```

Then change only the Bell class:

```tsx
<Bell size={iconSize} className={`block h-5 w-5 shrink-0 ${iconClassName}`} />
```

Leave the badge span untouched so its red styling does not receive the blend mode.

- [ ] **Step 4: Pass the class to both shared notification instances**

Add this prop to both existing `NotificationBell` calls in `Navigation.tsx`:

```tsx
iconClassName={adaptiveLandingIconClass}
```

For the notification button color, preserve the existing white/dark branches while using white whenever `isAdaptiveLandingNav` is true:

```tsx
className={isAdaptiveLandingNav || isTransparentNav
  ? 'text-white hover:opacity-70'
  : 'text-gray-900 hover:opacity-70'}
```

- [ ] **Step 5: Run the focused test to verify the implementation passes**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
```

Expected: PASS, including the new adaptive-icon contract and all existing navigation contracts.

### Task 3: Verify build, diff hygiene, and browser behavior

**Files:**
- Inspect: `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- Inspect: `resources/js/components/common/NotificationBell.tsx`
- Inspect: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`

**Interfaces:**
- Consumes: the passing focused contract from Task 2.
- Produces: fresh build and browser evidence that icon colors change with the visible landing content while badges and layout remain intact.

- [ ] **Step 1: Run the production frontend build**

Run:

```bash
pnpm run build
```

Expected: Vite completes successfully without TypeScript/JSX or Tailwind class-generation errors.

- [ ] **Step 2: Inspect the final working-tree scope and whitespace**

Run:

```bash
git status --short
git diff --stat
git diff --check
```

Expected: only the approved spec, plan, and adaptive navigation files are changed by this task; existing auth edits and untracked user files remain present and untouched; `git diff --check` prints no errors.

- [ ] **Step 3: Verify the landing page in a browser**

Open the landing page at the local app, confirm the five requested controls are visible over the initial hero, scroll until the same fixed controls overlay the hero/image and then light content, and confirm the controls remain readable. Confirm opening the menu, search, cart, and notification controls still works and red badges retain their red color.
