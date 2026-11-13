# ERP, Showroom, and CRM UI Polish Design

## Goal

Resolve three isolated UI issues without changing business logic: prevent the
standalone showroom controls from colliding on portrait phones, keep the ERP
approval dialog and SweetAlert confirmation above the sticky navbar, and remove
the CRM customer detail modal's edit action.

## Scope and behavior

- The standalone showroom's back link remains at the top-left on desktop and
  moves to a compact phone-safe position. Its Night/Day control moves below it
  only below the `sm` breakpoint, with a visible gap between the controls.
- Manager approval continues to call the existing `workflowFeedback.confirm`
  helper. That helper owns the SweetAlert2 confirmation and the existing API
  decision flow remains unchanged.
- The ERP header uses a normal application chrome layer (`z-40`), while the
  Suspension Approvals detail and rejection dialogs use `z-[100]`. This keeps
  both dialogs above the header and leaves SweetAlert2's own overlay above the
  application UI.
- The CRM customer detail modal no longer renders the `Edit Customer` button.
  Customer loading, read-only details, purchase history, repair history, notes,
  and close behavior remain unchanged.

## Files

- `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx` — responsive
  position for the standalone back control.
- `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx` — responsive
  position and spacing for the standalone Night/Day control.
- `resources/js/layout/AppHeader_ERP.tsx` — bounded sticky header and compact
  application-menu layer.
- `resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx` — dialog layer
  ordering; the existing SweetAlert2 approval path is retained.
- `resources/js/Pages/ERP/CRM/Customers.tsx` — remove the visible edit action.
- Existing/new focused Vitest contracts — lock down the responsive classes,
  z-index relationship, SweetAlert path, and absent CRM action.

## Validation

Run the focused frontend tests while iterating, then the full frontend suite,
the Laravel Logistics feature suite, `git diff --check`, and a fresh Vite build.
Use a headless browser check for the public showroom when the local server is
available; protected ERP routes may require authenticated state and are covered
by component/contract tests when that state is unavailable.

## Risks and non-goals

This is a presentation-only change. It does not alter routes, API payloads,
authorization, database data, or the customer edit endpoint. No dependency,
global theme token, or unrelated layout is changed.
