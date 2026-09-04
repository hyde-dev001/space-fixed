# Repair Shop Information and Rating Layout

## Goal

Combine the Shop Information and Customer Rating panels on the repair shop details page into one cohesive, responsive table-style container. Improve hierarchy and spacing without changing shop data, review data, booking, messaging, reporting, or delivery behavior.

## Chosen Layout

Use one outer rounded container with a shared border and shadow. Keep the existing Shop Information heading and Message/more-actions controls in the shared header.

Inside the container:

- On desktop, use a two-column layout with Shop Information on the wider left side and Customer Rating on the right side.
- Separate the columns with a subtle vertical divider; on mobile, stack them with a horizontal divider and no horizontal scrolling.
- Present Location, Repair Payment Policy, Hours, Phone, and Email as consistent information rows instead of separate floating cards.
- Keep the existing rating summary, star rendering, review count, and empty state in the rating column.
- Use the existing black, white, and gray visual language for the shared container and information rows. Remove the current blue and yellow block backgrounds from this section while keeping rating meaning conveyed by text and star icons.

This is a visual table-style layout, not a literal HTML `<table>`, because the content includes responsive information rows, links, and interactive actions that need to remain readable on narrow screens.

## Behavior and Accessibility

Keep all current state, handlers, links, API calls, and conditional rendering unchanged. Preserve the Message link, authenticated Report menu, current-hours indicator, payment policy copy, review statistics, and no-reviews message.

Use a semantic section heading relationship for the unified container, preserve accessible labels on icon-only controls, keep keyboard focus styles, and ensure the column order remains logical when stacked on mobile.

## Scope and Verification

Modify only the repair shop details presentation and its focused frontend test. Do not change controllers, routes, review endpoints, booking payloads, or shared navigation.

Verify that:

1. Both headings render inside one shared container with the desktop divider and mobile stacking classes.
2. Existing shop information and rating states remain present.
3. The blue payment-policy and yellow rating block backgrounds are absent from this section.
4. The focused repair shop layout test passes, the full frontend test suite passes, and the production Vite build succeeds.

