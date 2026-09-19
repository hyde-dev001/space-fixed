# Logistics Settings Dispatcher Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Logistics Dispatchers default access to Logistics Settings and a permission-gated clickable ERP sidebar item.

**Architecture:** Reuse Spatie's existing seeded role permissions and the existing flat ERP sidebar/filter pattern. Server-side controller and API authorization remain unchanged and continue to enforce the permission.

**Tech Stack:** Laravel 12, Spatie Laravel Permission, React/TypeScript, Inertia, PHPUnit, Vite

---

### Task 1: Seed dispatcher settings permission

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsSeederTest.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php:297-310`

- [ ] **Step 1: Write the failing test**

After seeding, load the `Logistics Dispatcher` role and assert it has the permission:

```php
$dispatcher = \Spatie\Permission\Models\Role::findByName('Logistics Dispatcher', 'user');
$this->assertTrue($dispatcher->hasPermissionTo('configure-logistics-settings'));
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Logistics/LogisticsSeederTest.php`

Expected: FAIL because the dispatcher role does not yet have `configure-logistics-settings`.

- [ ] **Step 3: Write the minimal implementation**

Add one entry to the existing `$logisticsDispatcher->syncPermissions([...])` array:

```php
'configure-logistics-settings',
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Logistics/LogisticsSeederTest.php`

Expected: PASS.

- [ ] **Step 5: Commit the seeded permission**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Logistics/LogisticsSeederTest.php
git commit -m "feat: grant dispatchers logistics settings access"
```

### Task 2: Add permission-gated ERP Settings navigation

**Files:**
- Create: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx:584-613`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx:828-832`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx:905-909`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx:1538-1550`

- [ ] **Step 1: Write the failing permission-gated sidebar test**

Mock `usePage`, `Link`, `route`, and `useSidebar` using the same Vitest/Testing Library stack already installed. Render `AppSidebar_ERP` first with `configure-logistics-settings` and assert a `Settings` link exists with `/erp/logistics/settings`; render without that permission and assert it is absent.

```tsx
import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AppSidebarERP from '../AppSidebar_ERP';

const state = vi.hoisted(() => ({ permissions: [] as string[] }));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ url: '/erp/logistics', props: { auth: { user: { role: 'Logistics Dispatcher', roles: ['Logistics Dispatcher'] }, permissions: state.permissions } } }),
  Link: ({ href, children }: React.PropsWithChildren<{ href: string }>) => <a href={href}>{children}</a>,
}));
vi.mock('ziggy-js', () => ({ route: (name: string) => {
  if (name === 'landing') return '/';
  throw new Error('use sidebar fallback map');
} }));
vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => ({
    isExpanded: true, isMobileOpen: false, isHovered: false,
    setIsHovered: vi.fn(), toggleMobileSidebar: vi.fn(),
    openSubmenu: null, toggleSubmenu: vi.fn(), setOpenSubmenu: vi.fn(),
  }),
}));

it('shows logistics settings only with its permission', () => {
  state.permissions = ['access-logistics-dashboard', 'configure-logistics-settings'];
  const { unmount } = render(<AppSidebarERP />);
  expect(screen.getByRole('link', { name: /settings/i })).toHaveAttribute('href', '/erp/logistics/settings');
  unmount();

  state.permissions = ['access-logistics-dashboard'];
  render(<AppSidebarERP />);
  expect(screen.queryByRole('link', { name: /settings/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run the frontend test to verify it fails**

Run: `npx vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`

Expected: FAIL because no Logistics Settings sidebar item exists.

- [ ] **Step 3: Add the Settings navigation item**

Follow the existing Logistics/Riders item shape and add:

```tsx
{
  icon: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.1A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1A1.7 1.7 0 0 0 15.4 4a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.12.38.34.72.65 1 .3.27.7.4 1.1.4H21v4h-.1c-.4 0-.8.13-1.1.4-.3.28-.53.62-.65 1Z" />
    </svg>
  ),
  name: "Settings",
  route: "erp.logistics.settings",
},
```

- [ ] **Step 4: Register the route path in both existing route maps**

Add:

```ts
"erp.logistics.settings": "/erp/logistics/settings",
```

- [ ] **Step 5: Gate the item with the existing permission filter**

Add:

```ts
if (item.route === "erp.logistics.settings") {
  return permissions.includes('configure-logistics-settings');
}
```

- [ ] **Step 6: Run the focused frontend test to verify it passes**

Run: `npx vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`

Expected: PASS for both permission states and the exact link target.

- [ ] **Step 7: Verify TypeScript and production rendering**

Run: `npm run build`

Expected: Vite build exits 0.

- [ ] **Step 8: Commit the navigation**

```bash
git add resources/js/layout/AppSidebar_ERP.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
git commit -m "feat: link dispatcher logistics settings"
```

### Task 3: Regression verification and commit

**Files:**
- Verify all modified files above.

- [ ] **Step 1: Run logistics feature tests**

Run: `php artisan test tests/Feature/Logistics`

Expected: all logistics tests pass.

- [ ] **Step 2: Run focused frontend navigation test**

Run: `npx vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`

Expected: PASS.

- [ ] **Step 3: Check the diff**

Run: `git diff --check && git status --short`

Expected: no whitespace errors; only intended files and existing user-owned untracked files appear.
