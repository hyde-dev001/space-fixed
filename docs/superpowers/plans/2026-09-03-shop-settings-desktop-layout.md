# Shop Settings Desktop Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with review checkpoints.

**Goal:** Make the Shop Settings page a compact, understandable desktop settings workspace while preserving the current tablet/mobile layout and all settings behavior.

**Architecture:** Keep `shopSetting.tsx` as the existing page and add a desktop-only presentation shell around its existing navigation and section stack. At `xl` (1280px+) the shell becomes a two-column grid with a sticky vertical rail and the existing content becomes a natural flex column; below `xl`, `contents` and the existing grid classes preserve the current layout. Use `xl:order-*` only on existing section wrappers to create a meaningful desktop reading order without moving DOM nodes or duplicating controls.

**Tech Stack:** Laravel/Inertia React 18, TypeScript, Tailwind CSS 4, Vitest, Testing Library, Vite.

## Global Constraints

- Keep the current base, small-screen, and tablet classes and DOM behavior intact.
- Apply the new shell at Tailwind's `xl` breakpoint (1280px and above), so a 1024px tablet remains on the existing layout.
- Modify only the desktop presentation of `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx` and focused frontend coverage; do not change backend payloads, routes, validation, or business logic.
- Preserve one accessible `Settings sections` navigation with the same six anchor destinations and active-state/focus behavior.
- Do not rename or remove `settings-section-*` IDs, refs, labels, controls, or interactive handlers.
- Do not introduce a new dependency, data source, or duplicated navigation.
- Use the neutral ink, soft-cloud, canvas, hairline, spacing, and flat-elevation guidance from the repository `DESIGN.md` as the visual reference.

---

### Task 1: Add the desktop layout contract test

**Files:**
- Modify: `resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx` after the existing navigation tests.

**Interfaces:**
- Consumes: the existing `renderSettings()` fixture and the existing mocked `ShopSetting` page.
- Produces: a regression test for `data-testid="settings-desktop-shell"`, `data-testid="settings-content"`, desktop-only shell classes, and desktop section order classes.

- [ ] **Step 1: Write the failing test**

Add this test inside the existing `describe("canonical settings sections", ...)` block:

~~~
  it("exposes a desktop settings shell without replacing the base/tablet layout contract", () => {
    renderSettings("profile");

    const shell = screen.getByTestId("settings-desktop-shell");
    const navigation = screen.getByRole("navigation", { name: "Settings sections" });
    const content = screen.getByTestId("settings-content");

    expect(shell).toHaveClass("contents", "xl:grid", "xl:grid-cols-[220px_minmax(0,1fr)]", "xl:gap-10");
    expect(navigation).toHaveClass("mb-6", "rounded-2xl", "border", "bg-white", "p-2", "shadow-sm");
    expect(navigation).toHaveClass("xl:sticky", "xl:top-6", "xl:mb-0", "xl:shadow-none");
    expect(content).toHaveClass("grid", "grid-cols-1", "gap-6", "lg:grid-cols-12");
    expect(content).toHaveClass("xl:flex", "xl:min-w-0", "xl:flex-col", "xl:gap-6");

    expect(document.getElementById("settings-section-profile")).toHaveClass("xl:order-1");
    expect(document.getElementById("settings-section-modules-team")).toHaveClass("xl:order-2");
    expect(document.getElementById("settings-section-policies-compliance")).toHaveClass("xl:order-10");
    expect(document.getElementById("settings-section-operations")).toHaveClass("xl:order-8");
    expect(document.getElementById("settings-section-payments-approvals")).toHaveClass("xl:order-4");
  });
~~~

The test intentionally checks both the existing non-`xl` classes and the new `xl:` overrides. This makes an accidental tablet/mobile replacement visible in review without requiring a CSS runtime in jsdom.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx --reporter=dot --maxWorkers=1 --minWorkers=1
~~~

Expected: the existing three tests pass and the new test fails because the page does not yet expose the two test IDs or the `xl:` desktop classes.

### Task 2: Add the desktop-only shell and natural section flow

**Files:**
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx:1999-3120` (render layout only).

**Interfaces:**
- Consumes: existing `SETTINGS_SECTION_OPTIONS`, `activeSettingsSection`, `selectSettingsSection`, `setSettingsSectionRef`, conditional section flags, and all existing section handlers.
- Produces: one existing navigation rail and one existing settings content stack, with no new state or behavior.

- [ ] **Step 1: Widen the page container only at desktop**

Change the page container class from:

~~~tsx
className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8"
~~~

to:

~~~tsx
className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8 xl:max-w-[1440px] xl:px-10 2xl:px-16"
~~~

The original classes remain active below `xl`.

- [ ] **Step 2: Wrap the existing navigation and content without changing their small-screen flow**

Insert this wrapper immediately before the existing settings `<nav>` and close it immediately after the existing settings content wrapper:

~~~tsx
<div
  data-testid="settings-desktop-shell"
  className="contents xl:grid xl:grid-cols-[220px_minmax(0,1fr)] xl:items-start xl:gap-10"
>
  {/* existing Settings sections nav */}
  {/* existing settings content */}
</div>
~~~

Use `contents` at the base breakpoint so the wrapper has no layout box on mobile/tablet, then switch it to the two-column desktop shell at `xl`.

- [ ] **Step 3: Turn the existing navigation into a desktop rail while preserving its current base classes**

Keep the existing `nav` class tokens and append these desktop overrides:

~~~tsx
className="mb-6 rounded-2xl border border-gray-200 bg-white p-2 shadow-sm xl:sticky xl:top-6 xl:mb-0 xl:max-h-[calc(100vh-3rem)] xl:overflow-y-auto xl:rounded-xl xl:p-3 xl:shadow-none"
~~~

Inside the nav, add this desktop-only context block before the existing links container:

~~~tsx
<div className="mb-3 hidden xl:block">
  <p className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500">Settings</p>
  <p className="mt-1 text-sm font-medium text-gray-900">Manage your shop workspace</p>
</div>
~~~

Keep the existing links container classes and append `xl:flex-col xl:flex-nowrap xl:items-stretch xl:gap-0.5`. Keep every link's current label, href, click handler, `aria-current`, and focus classes; append `xl:flex xl:w-full xl:justify-start xl:px-4 xl:py-3 xl:text-left` so the same links read as a vertical rail only at desktop.

- [ ] **Step 4: Replace the desktop grid flow with a single flex stack**

Change the existing content wrapper class from:

~~~tsx
className="grid grid-cols-1 gap-6 lg:grid-cols-12"
~~~

to:

~~~tsx
className="grid grid-cols-1 gap-6 lg:grid-cols-12 xl:flex xl:min-w-0 xl:flex-col xl:gap-6"
~~~

Add `data-testid="settings-content"` to this same wrapper. At `xl`, the existing `lg:col-span-*` classes no longer participate in grid placement, so each existing section consumes the content column at its natural width instead of leaving grid columns and rows empty.

- [ ] **Step 5: Add explicit desktop order to existing section wrappers**

Append the following `xl:order-*` class to each existing direct child of the content wrapper, retaining all current classes and conditional expressions:

~~~text
Profile card                         xl:order-1
Modules & Team wrapper               xl:order-2
Business Document Compliance wrapper  xl:order-3
Approval Workflow card               xl:order-4
Payroll Cycle/Cutoff card             xl:order-5
Payment Gateway card                  xl:order-6
Refund Deadline card                  xl:order-7
Operations/Geofence card              xl:order-8
Repair Payment Policy card            xl:order-9
Policies & Compliance card            xl:order-10
~~~

Use only the `xl:` order utilities; do not remove or alter the existing `lg:order-*` classes. Add `xl:hidden` to the two decorative profile blur elements so the desktop hierarchy stays flat while their current tablet/mobile appearance remains unchanged. Add `xl:shadow-none` to the existing section cards where the card already has `shadow-sm`; retain the current base shadow tokens so smaller breakpoints are unchanged.

- [ ] **Step 6: Run the focused test and inspect the diff**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx --reporter=dot --maxWorkers=1 --minWorkers=1
git diff --check
git diff --stat
~~~

Expected: all canonical settings tests pass; the diff contains only the settings page, its focused test, and the already committed plan/spec documentation.

### Task 3: Verify behavior, responsive safety, and production output

**Files:**
- Modify: none unless a verification failure identifies a concrete regression in the files above.

**Interfaces:**
- Consumes: the desktop shell and existing settings interactions from Tasks 1–2.
- Produces: fresh test, build, and browser evidence for the final handoff.

- [ ] **Step 1: Run focused settings tests**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx --reporter=dot --maxWorkers=1 --minWorkers=1
~~~

Expected: all tests in both settings files pass.

- [ ] **Step 2: Run the full frontend test suite**

Run:

~~~powershell
pnpm run test:frontend
~~~

If PowerShell's pnpm shim is blocked, run the repository-local Vitest command directly:

~~~powershell
.\node_modules\.bin\vitest.cmd run --reporter=dot --maxWorkers=1 --minWorkers=1
~~~

Expected: no new failures; record any pre-existing warnings separately from failures.

- [ ] **Step 3: Build the production frontend**

Run:

~~~powershell
pnpm run build
~~~

If the pnpm shim is blocked, run:

~~~powershell
.\node_modules\.bin\vite.cmd build
~~~

Expected: Vite completes successfully and refreshes `public/build` as generated output. Do not hand-edit generated assets.

- [ ] **Step 4: Run browser verification at the three required widths**

Use the repository's Playwright/webapp-testing workflow against the local Shop Settings page and capture evidence at 1440px, 1024px, and 390px. Confirm:

~~~text
1440px: visible left rail, readable single content stack, no large grid gaps, no horizontal overflow.
1024px: existing horizontal navigation/content arrangement remains in place; no desktop rail is active.
390px: existing compact flow remains usable; no horizontal overflow or clipped controls.
All widths: six nav links exist, active section focus/scroll works, no page errors or new console errors.
~~~

- [ ] **Step 5: Run final hygiene checks**

Run:

~~~powershell
git diff --check
git status --short
~~~

Expected: no whitespace errors; only the intended settings page/test and plan/spec changes are present, plus generated `public/build` output if the repository tracks it.

- [ ] **Step 6: Commit the implementation**

After all checks pass, commit the implementation and verification-ready generated output:

~~~powershell
git add resources/js/Pages/ShopOwner/Settings/shopSetting.tsx resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx public/build docs/superpowers/plans/2026-09-03-shop-settings-desktop-layout.md
git commit -m "feat: organize shop settings desktop layout"
~~~

Do not stage unrelated working-tree files. Do not push or create a PR unless the user explicitly requests it.
