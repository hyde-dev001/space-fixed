# Customer Address Modal and Light Customer Pages Design

## Goal

Improve the Repair Services address interaction and keep customer-facing registration and notification pages light even when a shop-owner/ERP dark theme is saved.

## Scope and acceptance criteria

### Repair Services address interaction

- Keep the existing address API, address validation, map picker, selection callback, and coverage requests unchanged.
- Keep the saved-address summary visible on the page, but do not render the add/edit form in the page flow.
- Place an underlined `Add address` text action in the same control row as `Sort by: Near me`, immediately to its left on desktop and in a predictable wrapped row on small screens.
- Clicking `Add address` opens a light, centered modal titled `Add delivery address`.
- Clicking an address `Edit` action opens the same modal titled `Edit delivery address` with the existing values.
- The modal supports a visible close control, Cancel, Escape, backdrop dismissal, keyboard focus, a scrollable form on small screens, and body-scroll locking while open.
- The modal retains the existing map-pin requirement and displays the existing loading, validation, server-error, and save feedback.
- `Save address` remains the modal's primary submit button; the underlined control is only the add-address trigger.

### Light-only customer pages

- `UserSide/Auth/ShopOwnerRegistration` and `Notifications/CustomerNotifications` always render with the established light palette.
- Registration inputs, labels, cards, and step content remain readable and light when `localStorage.theme` is `dark`.
- Customer notifications remains a light page with readable filters, cards, controls, and empty/loading/error states.
- Shop-owner, ERP, and other dark-mode pages retain their current theme behavior.
- Theme persistence and the existing theme toggle continue to work; only the two light-only page scopes suppress the dark utility class.

## Interaction and visual direction

Use the existing SoleSpace customer visual language: white surfaces, neutral borders, dark navy primary actions, restrained shadows, 4/8px spacing rhythm, visible focus rings, and existing typography. Use semantic HTML and SVG icons already used by the project; do not add a dependency or introduce a second component library.

The modal uses a 40–60% neutral scrim, a max-width that remains comfortable beside the map picker, `max-h` plus internal scrolling, and a single primary action. Required fields retain visible labels and errors remain adjacent to the relevant form area.

## Data flow and boundaries

`Repair.tsx` owns the sort-row placement and continues to consume `CustomerAddressManager`'s `onSelect` callback. `CustomerAddressManager` owns modal state, form state, address fetching, saving, and focus/scroll behavior. A small page-theme helper owns only the list of components that are forced light and the `dark` class synchronization; it does not change API data or business rules.

## Verification

- Address component tests cover closed-by-default behavior, add/edit modal opening, cancel/Escape/backdrop close, validation, successful save, and existing server-error behavior.
- Page-theme tests cover both light-only components and a dark-enabled component without mutating unrelated behavior.
- Run focused Vitest, the full frontend suite, the relevant Laravel logistics test, Vite production build, and `git diff --check`.

