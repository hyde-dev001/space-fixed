# ERP Compact Application Menu Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Move the ERP compact application-menu theme control into the menu header and remove its duplicate inline account card without changing the desktop header.

**Architecture:** Keep the existing \`AppHeader_ERP\` structure and \`xl\` desktop boundary. Render one compact-only theme button in the open menu header, remove only the compact \`inline\` account rendering and compact appearance card, and retain the existing desktop theme/account slots unchanged. Verify the DOM contract through the existing \`AppHeader_ERP\` test mocks and responsive utility classes.

**Tech Stack:** React 18, TypeScript 5.7, Inertia React, Tailwind CSS 4, Testing Library, Vitest, Vite 7.

## Global Constraints

- Desktop navigation and account behavior must remain unchanged.
- At compact/mobile and tablet widths below the existing \`xl\` desktop boundary, the open application menu shows the title and a right-side theme toggle button.
- The compact application menu no longer renders the duplicate account identity, \`Profile & Password\`, or \`Sign Out\` content.
- No backend, route, account, authentication, or theme-state contract changes are required.
- Preserve accessible labels, focus styles, and existing application-menu open/close behavior.
- Preserve unrelated working-tree changes and stage only files belonging to this task.

---

### Task 1: Add the failing compact-menu regression coverage

**Files:**
- Modify: \`resources/js/layout/__tests__/AppHeader_ERP.test.tsx:41-43,154-219\`

**Interfaces:**
- Consumes: the existing \`AppHeaderERP\` render contract, mocked \`ThemeToggleButton\`, and mocked inline/non-inline account dropdown identifiers.
- Produces: assertions that the compact menu owns a header theme control and does not own the duplicate inline account card, while the existing desktop account dropdown remains rendered.

- [ ] **Step 1: Make the theme mock behave like the real accessible control**

Replace the current theme mock with:

    vi.mock('../../components/common/ThemeToggleButton', () => ({
      ThemeToggleButton: () => <button type="button" aria-label="Toggle theme" data-testid="theme-toggle" />,
    }));

- [ ] **Step 2: Update the compact-menu test to assert the approved UI**

After opening the compact menu, locate the application-menu header from its \`Application menu\` heading and assert the header contains the theme button. The test must also assert that \`Appearance\`, \`Account\`, the inline account test id, \`Shop Profile\`, and \`Sign Out\` are absent inside the compact menu, while the non-inline desktop account dropdown remains present:

    const menu = screen.getByRole('region', { name: 'Application menu' });
    const title = within(menu).getByRole('heading', { name: 'Application menu' });
    const menuHeader = title.parentElement?.parentElement;

    expect(menuHeader).not.toBeNull();
    expect(within(menuHeader as HTMLElement).getByTestId('theme-toggle')).toBeInTheDocument();
    expect(within(menu).queryByText('Appearance')).not.toBeInTheDocument();
    expect(within(menu).queryByText('Account')).not.toBeInTheDocument();
    expect(within(menu).queryByTestId('inline-shop-owner-dropdown')).not.toBeInTheDocument();
    expect(within(menu).queryByText('Shop Profile')).not.toBeInTheDocument();
    expect(within(menu).queryByText('Sign Out')).not.toBeInTheDocument();
    expect(screen.getByTestId('shop-owner-dropdown')).toBeInTheDocument();

Keep the existing notification, menu semantics, Escape close, focus restore, and grid assertions that are still valid.

- [ ] **Step 3: Replace the regular-user inline-account test**

Rename the test to describe the new contract and assert that the regular-user compact menu has no inline account dropdown or account action text, while the non-inline user dropdown remains rendered:

    it('keeps duplicate account actions out of the compact application menu', () => {
      state.url = '/erp/staff/dashboard';
      state.props = {
        auth: {
          erpActor: { type: 'employee', id: 11, name: 'Staff User', guard: 'user', ownerMode: false, tenantOwnerId: 7 },
          user: { name: 'Daniel Cruz', email: 'logistics.dispatcher.2@solespace.com', role: 'STAFF', roles: ['Logistics Dispatcher'] },
          shop_owner: null,
        },
        erpUrls: { profile: '/erp/profile' },
      };

      render(<AppHeaderERP />);
      fireEvent.click(screen.getByRole('button', { name: 'Toggle Application Menu' }));

      const menu = screen.getByRole('region', { name: 'Application menu' });
      expect(within(menu).queryByTestId('inline-user-dropdown')).not.toBeInTheDocument();
      expect(within(menu).queryByText('Profile & Password')).not.toBeInTheDocument();
      expect(within(menu).queryByText('Sign Out')).not.toBeInTheDocument();
      expect(screen.getByTestId('user-dropdown')).toBeInTheDocument();
    });

- [ ] **Step 4: Run the focused test and verify it fails for the old UI**

Run:

    .\node_modules\.bin\vitest.CMD run --pool=threads --maxWorkers=1 --minWorkers=1 resources/js/layout/__tests__/AppHeader_ERP.test.tsx

Expected: FAIL because the current compact menu still renders \`Appearance\`, the inline account dropdown, and its account actions, and the theme mock is not yet a button in the menu header.

### Task 2: Implement the compact-only header layout

**Files:**
- Modify: \`resources/js/layout/AppHeader_ERP.tsx:241-275\`

**Interfaces:**
- Consumes: the existing \`ThemeToggleButton\`, \`renderAccountMenu\`, notification action, and \`xl\` utility classes.
- Produces: a compact menu header with a right-side theme button; the unchanged desktop theme/account action slots.

- [ ] **Step 1: Render the existing theme button in the compact menu header**

Change the open-menu header to use a two-column flex layout and append this compact-only control after the title/description block:

    <div className="flex items-start justify-between gap-3">
      <div>
        <h2 id="application-menu-title" className="text-base font-semibold text-gray-900 dark:text-white">Application menu</h2>
        <p id="application-menu-description" className="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">Appearance and account settings.</p>
      </div>
      <div className="xl:hidden">
        <ThemeToggleButton />
      </div>
    </div>

The existing header remains \`xl:hidden\`, and the actual theme button keeps its existing accessible name and focus-visible styling.

- [ ] **Step 2: Remove only compact appearance/account content**

Delete the compact Appearance wrapper containing the mobile label and \`ThemeToggleButton\`, and delete the compact wrapper that calls \`renderAccountMenu(true)\`. Keep the desktop-only theme/account wrapper and its \`renderAccountMenu()\` call in the same desktop action area so desktop markup, routes, and dropdown behavior remain unchanged.

- [ ] **Step 3: Run the focused test and verify it passes**

Run the same Vitest command from Task 1. Expected: all \`AppHeader_ERP\` tests PASS, including compact menu close/focus behavior, notification rendering, compact theme placement, absence of duplicate account actions, and retained desktop account rendering.

### Task 3: Run the project verification and review gates

**Files:**
- Review: \`resources/js/layout/AppHeader_ERP.tsx\`
- Review: \`resources/js/layout/__tests__/AppHeader_ERP.test.tsx\`
- Review: \`docs/superpowers/specs/2026-08-25-erp-compact-application-menu-design.md\`

**Interfaces:**
- Consumes: the implementation and focused regression evidence from Tasks 1-2.
- Produces: verified source, tests, and generated production assets ready for a feature-branch commit.

- [ ] **Step 1: Run the full frontend suite**

Run:

    .\node_modules\.bin\vitest.CMD run --pool=threads --maxWorkers=1 --minWorkers=1

Expected: the complete frontend suite passes with no new failures.

- [ ] **Step 2: Run the production build**

Run:

    .\node_modules\.bin\vite.CMD build

Expected: Vite completes successfully and refreshes \`public/build\` assets and \`public/build/manifest.json\`.

- [ ] **Step 3: Run diff hygiene and manual review**

Run:

    git diff --check
    git status --short
    git diff -- resources/js/layout/AppHeader_ERP.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx

Confirm the diff changes only compact (\`xl:hidden\`/base) presentation and test coverage, leaves desktop \`xl:\` layout/account behavior intact, contains no unused imports or stale inline-account references, and does not include the unrelated user files.

- [ ] **Step 4: Stage and commit only the task files**

Stage the source, focused test, and generated \`public/build\` output plus this implementation plan. Do not stage \`app/Http/Controllers/Logistics/ErpLogisticsController.php\`, \`package-lock.json\`, \`tests/Feature/Logistics/LogisticsPageAccessTest.php\`, \`.pnpm-store/\`, or \`DESIGN.md\`.

    git add resources/js/layout/AppHeader_ERP.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx docs/superpowers/plans/2026-08-25-erp-compact-application-menu.md public/build
    git diff --cached --check
    git diff --cached --stat
    git commit -m "fix: simplify compact ERP application menu"

- [ ] **Step 5: Rebase safely and push the feature branch**

If the unrelated working-tree files are still present, stash them with a named untracked-inclusive stash before rebasing, then restore them after the push:

    git stash push -u -m "before pushing compact ERP menu update"
    git fetch origin --prune
    git rebase origin/solespace-b
    git push -u origin fix/darkmode-selected-size
    git stash pop

If the stash is not needed, fetch/rebase/push without stashing. Confirm the remote branch advances and no PR is created.

