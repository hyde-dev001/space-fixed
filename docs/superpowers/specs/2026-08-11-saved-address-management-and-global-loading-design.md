# Saved Address Management and Global Loading Design

**Date:** 2026-08-11  
**Status:** Approved for implementation

## Goal

Keep every customer address saved after new addresses are added, expose safe edit/default/delete actions, enforce digits-only phone input, and add a polished global first-load animation across customer, shop-owner, and ERP pages.

## Constraints

- Reuse the existing `/api/user/addresses` GET, POST, PUT, DELETE, and `/{id}/set-default` endpoints.
- Do not change the address schema or booking/order payload contracts.
- Preserve leading zeroes in phone values by treating phone numbers as digit strings, not JavaScript numbers.
- Preserve the existing `package-lock.json` and untracked `DESIGN.md` working-tree changes.
- Follow `DESIGN.md`: monochrome editorial chrome, 8px spacing rhythm, readable labels, restrained accents, and no emoji icons.
- Respect `prefers-reduced-motion` and keep the loader non-blocking after the application is ready.

## Root Cause

`CustomerAddressManager` currently loads addresses and merges a newly-created address into local state, but it does not consume the existing delete or set-default endpoints. Its UI has no delete/default actions, and its mutation state is not reconciled with the server after a mutation. This makes the saved-address experience incomplete and leaves the list vulnerable to stale or incomplete local state when the component is remounted.

## Design

### Address management

`CustomerAddressManager` remains the single owner of the saved-address list. It will use a small request/reload flow:

1. Load the full server list on mount.
2. After create, update, delete, or set-default, reload the full list from the server.
3. Preserve the current selection when the selected address still exists; otherwise choose the server default, then the first remaining address, or clear selection when none remain.
4. Call `onSelect` only with an address that exists in the latest server list.

Each address card will provide one clear selection control and secondary text actions for `Edit`, `Set as default`, and `Delete`. Delete requires confirmation and displays an inline busy state. The existing modal remains the add/edit form, and mutation errors stay inside the modal or the address summary rather than replacing the page.

The phone field will use `type="tel"`, `inputMode="numeric"`, a digits-only input sanitizer, and a visible helper message. The Laravel controller will also validate `phone` with a digits-only rule so direct requests cannot store alphabetic or punctuation values.

### Global first-load animation

The Blade app shell will render a lightweight critical preloader before the Inertia root. It will use the `DESIGN.md` black/white canvas, a small SoleSpace wordmark, and a code-native sneaker outline with a restrained cobalt progress line. The animation will use opacity and transform only, run for a short handoff window, and be removed by the Inertia bootstrap after the first page is rendered.

The preloader will have:

- a fixed high stacking layer and no effect on the page after dismissal;
- a `role="status"` label for assistive technology;
- a reduced-motion variant that removes the continuous shoe animation;
- no external image, font, or runtime dependency;
- a safe fallback so the document remains usable if motion is disabled.

## Error and state handling

- Disable only the address action currently being submitted or deleted.
- Show server validation messages near the form or summary.
- Keep the modal open after a failed mutation so entered data is not lost.
- If reloading the list after a successful mutation fails, keep the optimistic result only when its identity is known and show a recoverable refresh error; never silently clear the existing list.
- Do not block normal clicks or navigation after the initial application handoff.

## Verification

- Component tests prove a newly-created address does not remove the old address.
- Component tests cover edit, set-default, delete, selection fallback, and digits-only phone behavior.
- Loader tests verify the preloader dismissal hook and reduced-motion CSS contract.
- Run the focused Vitest files, then the full frontend test suite, `pnpm run build`, `git diff --check`, and browser verification on the repair-services page when the local app is runnable.
