# ERP Workspace Desktop UI Refresh Design

**Date:** 2026-08-14

**Status:** Approved for implementation

## Goal

Improve the desktop experience of the shop-owner ERP workspace without changing the server contract, authorization behavior, module visibility rules, or mobile experience.

## Scope

The change is limited to the shared shop-owner ERP shell and its workspace landing page:

- Make the **ERP Workspace** sidebar item visibly active on the workspace route, including query strings and trailing slashes.
- Keep the active state accessible with `aria-current="page"`.
- Remove the duplicate **Back to Shop Owner Portal** action from the ERP navbar.
- Keep the existing workspace-page portal link as the single return action and position it as a polished secondary action in the desktop hero.
- Refresh the desktop workspace hierarchy, card surfaces, spacing, status treatment, hover states, and focus states using existing SoleSpace/Tailwind conventions.

Mobile layout, mobile interaction behavior, server-provided URLs, module authorization, module filtering, and existing ERP page routing are out of scope.

## Design

The implementation uses the existing brand blue/navy palette and shared utility classes. Desktop-only Tailwind variants (`lg:` and above) provide the visual changes; base/mobile behavior remains unchanged.

The sidebar compares normalized paths rather than raw Inertia URLs. The normalized comparison removes the origin, query string, and trailing slash before matching. The workspace link receives the existing active utility classes plus a clearer desktop active rail/surface treatment and `aria-current` only when active.

The ERP header retains search, notifications, theme controls, and the owner profile dropdown. Its owner portal link is removed. The workspace hero remains the single source of truth for returning to the shop-owner portal. On desktop, the action is aligned to the hero’s upper-right as a secondary button; on mobile, the existing stacked flow is preserved.

The workspace page keeps all server-driven module arrays and URLs intact. Desktop presentation adds stronger visual hierarchy: a refined hero, clear owner/status context, more deliberate module card spacing, restrained hover elevation, explicit availability states, and consistent focus treatment. No new dependency or abstraction is introduced.

## Acceptance criteria

1. Only one `Back to Shop Owner Portal` link is rendered in the ERP workspace.
2. The link points to the server-provided portal URL.
3. The ERP Workspace sidebar link is active for `/shop-owner/erp/workspace`, including a query string or trailing slash.
4. The active sidebar link exposes `aria-current="page"` and remains keyboard focusable.
5. Existing owner and employee sidebar filtering behavior is unchanged.
6. Mobile markup/behavior and mobile visual flow remain unchanged.
7. Available, unavailable, empty, and manage-module states still render from the existing props and links.
8. Relevant frontend tests, production build, and `git diff --check` provide fresh verification evidence.

## Verification

- Run the focused workspace, ERP sidebar, and ERP header Vitest suites.
- Run `pnpm run build`.
- Run `git diff --check`.
- Review the diff to confirm unrelated working-tree changes are untouched.
