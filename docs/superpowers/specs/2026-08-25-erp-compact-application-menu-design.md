# ERP Compact Application Menu Design

## Goal

Simplify the ERP application menu on mobile and tablet-sized viewports by
keeping the appearance control compact and removing duplicate account content.
Desktop navigation and account behavior must remain unchanged.

## Root cause

`AppHeader_ERP.tsx` uses the existing `xl` breakpoint for the desktop shell, but
the compact application menu currently renders an inline account card with the
user identity, profile/password action, and sign-out action. The account
dropdown already owns those details and actions, so the compact menu duplicates
the account surface. The appearance control is also rendered as a full card,
which makes the menu taller than necessary.

## Chosen approach

Make the smallest responsive-only change in the shared ERP header:

1. Render the existing `ThemeToggleButton` as a right-aligned icon-only control
   in the compact application-menu header beside the title.
2. Remove the compact inline account card and its duplicate account copy from
   the application-menu content.
3. Keep the existing desktop theme control, desktop account dropdowns, routes,
   logout behavior, and breakpoint boundary unchanged.
4. Preserve accessible labels, focus styles, and the existing application-menu
   open/close behavior.

No backend, route, account, authentication, or theme-state contract changes are
required.

## Acceptance criteria

- At compact/mobile and tablet widths below the existing `xl` desktop boundary,
  the open application menu shows the title and a right-side theme toggle
  button.
- The compact application menu no longer renders the duplicate account identity,
  `Profile & Password`, or `Sign Out` content.
- The existing desktop header still renders its theme control and account
  dropdown, with no desktop-only class/layout changes.
- Existing menu dismissal, notification rendering, theme toggling, profile
  navigation, and logout behavior remain intact.
- Focus-visible treatment and an accessible name remain present on the icon-only
  theme button.

## Verification

- Add focused `AppHeader_ERP` regression tests for compact menu contents and
  desktop account/theme rendering.
- Run the focused frontend test, the full frontend suite, the production build,
  and `git diff --check`.
- Review the final diff for responsive-only scope and unrelated file changes.
