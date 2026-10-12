# Logistics Date Picker Design

## Goal

Replace the browser-native delivery date calendar with a compact ERP-styled single-date picker that matches the supplied reference and prevents selecting dates before the shop's current date.

## Design

- Keep the existing `date` string state and `onDateChange` callback so filtering, scheduling, router requests, and API payloads remain unchanged.
- Use the page's server-provided `today` value, already calculated in the shop timezone (`Asia/Manila`), as the minimum selectable date.
- Render a text-like trigger with the existing delivery-date label and a calendar icon. Open an inline calendar popover with month navigation, weekday headings, 7-column date cells, a blue selected date, muted disabled past dates, and a clear-date action.
- Keep today and future dates enabled. Disable previous-month navigation at the minimum month and disable all date buttons before `today` with native `disabled` semantics.
- Close on outside pointer interaction and Escape. Keep keyboard-focus rings and descriptive labels on every icon/date button.
- Do not change backend routes, validation, scheduling logic, router query parameters, or delivery filtering rules.

## Acceptance Criteria

1. The picker visually follows the supplied month-grid reference at desktop and narrow widths.
2. Dates before server `today` cannot be clicked or selected.
3. Today and future dates remain selectable and call the existing `onDateChange` flow.
4. Clear date resets the existing filter value without changing other filters.
5. Month navigation and Escape/outside-click dismissal work without affecting the page workspace.
6. Existing Logistics tests and the production build pass.
