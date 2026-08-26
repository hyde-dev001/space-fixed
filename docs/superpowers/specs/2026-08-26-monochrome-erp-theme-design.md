# SoleSpace Monochrome ERP Theme Design

## Goal

Update the shared SoleSpace application theme across manager, staff, finance, HR, CRM, cashier, repairer, inventory, procurement, logistics dispatcher, and shop-owner workspaces so the interface uses a white-and-black visual system with semantic status colors preserved.

## Scope

The change covers every connected page that consumes the shared application shell or shared UI primitives, including dashboards, module landing pages, settings, notifications, profile/account menus, forms, tables, cards, dialogs, and navigation for the listed roles. It also covers shop-owner company and individual businesses in retail, repair, retail-only, and repairer-only configurations wherever the same UI primitives are used.

The change is visual only. Routes, permissions, module eligibility, data flow, labels, API behavior, and role access must remain unchanged.

Existing unrelated working-tree changes, especially the Logistics controller/test changes and package changes, must be preserved.

## Visual Direction

- Canvas: white.
- Primary chrome: near-black (`#111111`) and white.
- Sidebar: solid black with white selected content and muted gray inactive content.
- Main text: near-black; secondary text: charcoal and neutral gray.
- Surfaces: white and soft gray (`#f5f5f5`).
- Borders and outlines: neutral gray hairlines; no decorative blue outlines.
- Primary buttons and active controls: black background with white text.
- Secondary controls: white or soft gray with black text and neutral borders.
- Logo and wordmark: black on light surfaces and white on the black sidebar; blue logo outlines become monochrome.
- Module cards: neutral border and surfaces; module icon tile, icon, “Open module” text, and arrow use black/gray rather than blue.
- Icons: retain the existing icon family and meaning; only presentation colors change unless a shared icon component requires a consistent monochrome default.
- Status colors remain semantic: green for success/ready, red for errors/danger, and amber for warnings/pending. They must not be used as general decorative chrome.
- Focus and keyboard states use high-contrast black/neutral rings and must remain visibly distinct.

### Dark Mode

- Canvas: near-black (`#0f0f10`) with charcoal surfaces (`#18181b`) and neutral hairline borders.
- Sidebar: black remains the anchor surface; selected items use white text and a neutral gray/white indicator, never blue.
- Text: white primary text, light gray secondary text, and medium gray metadata.
- Buttons: primary buttons remain black with white text and a visible neutral border; secondary buttons use charcoal/white with black-or-white text according to contrast. No button may become indistinguishable from its surrounding surface.
- Logo: white on dark surfaces and black on light surfaces; any blue outline becomes the matching monochrome foreground.
- Status colors remain semantic in dark mode, using accessible darker backgrounds with light green/red/amber text or borders.
- Focus states use a white or light-gray ring on dark surfaces and a black ring on light surfaces.

### Buttons

Every button in the connected system uses the monochrome luxury treatment, including primary CTAs, CTA links, module actions, form submits, table actions, pagination, dropdown actions, drawer actions, modal actions, nested modal actions, and SweetAlert2 confirm/cancel buttons. The default actionable treatment is black with white text; outlined/secondary variants remain neutral black/white with a black or neutral border and must not introduce blue, purple, or other decorative button colors. Existing button dimensions, labels, click behavior, and disabled behavior remain unchanged.

The visual tone is luxury and professional through restraint: near-black ink, pure white surfaces, soft-gray secondary surfaces, precise neutral hairlines, consistent typography, generous but controlled spacing, and subtle 150–250ms transitions. Avoid gradients, glossy effects, excessive shadows, loud accent fills, and inconsistent corner radii.

### SweetAlert2

All shared SweetAlert2 dialogs use the same monochrome design in both themes:

- Light mode: white popup, near-black text, neutral border/shadow, black confirm button, soft-gray cancel button.
- Dark mode: charcoal popup, white text, neutral border/shadow, black confirm button with visible light border, darker-gray cancel button with white text.
- Warning, error, success, and info icons retain semantic meaning and colors where needed, but their surrounding chrome remains monochrome.
- Backdrop, title, body, summary rows, inputs, actions, hover, and focus states must not use the old blue/navy palette.
- Existing SweetAlert animations and z-index behavior remain unchanged.

## Architecture

Use a token-first migration. Update the shared CSS/theme primitives and shared shell components first, then remove or replace remaining blue/indigo presentation in connected shared components and module landing surfaces. Prefer existing Tailwind/theme utilities and shared components over page-specific overrides.

The migration must not introduce a second theme system or change business-specific status color mappings. Existing dark-mode behavior should remain functional unless the shared theme implementation already treats the sidebar or shell as a fixed dark surface.

## Affected Areas

Likely change points include:

- `resources/css/app.css` and existing theme variables/utilities.
- `resources/js/context/ThemeContext.tsx` and `resources/js/utils/pageTheme.ts` if theme state or page-level mappings require adjustment.
- `resources/js/layout/AppLayout*.tsx`, `resources/js/layout/AppSidebar*.tsx`, and `resources/js/layout/AppHeader*.tsx`.
- Shared header/dropdown/notification/profile components.
- `resources/js/Pages/ERP/Workspace.tsx` and module landing components/cards.
- Shared UI buttons, badges, cards, forms, and approval/notification components that currently expose blue or indigo chrome.
- `DESIGN.md` only where the implementation contract needs to be aligned with the approved SoleSpace ERP monochrome direction; preserve its semantic status colors.

The exact file list will be confirmed during implementation by tracing shared usage and scanning the changed areas for blue/indigo presentation classes.

## Acceptance Criteria

1. All listed role workspaces render with a white main canvas and black sidebar/chrome where the shared shell is used.
2. SoleSpace logo/wordmark and its visible outlines no longer use light blue or blue decorative accents.
3. Module landing cards match the supplied direction: neutral outline, black icon/text, and black “Open module” action.
4. Shared settings, notification, profile, dropdown, form, table, modal, and header surfaces use the same monochrome tokens.
5. Every connected button, including buttons inside modals and nested dialogs, renders as black/neutral controls in light and dark mode with readable contrast.
6. SweetAlert2 dialogs, buttons, summaries, and inputs render in the monochrome system in light and dark mode.
7. The overall result reads as luxury, aesthetic, and professional through consistent spacing, typography, borders, radii, and restrained motion.
8. Blue/indigo presentation colors are removed from connected shared theme surfaces; remaining blue is documented as a deliberate non-theme exception or replaced.
9. Green, red, and amber semantic states remain available and readable.
10. No route, permission, module-access, API, or business workflow behavior changes.
11. Existing tests continue to pass, including shared layout/module landing tests and frontend tests.
12. The production frontend build succeeds and the diff has no whitespace errors.
13. The UI remains usable at desktop, tablet, and mobile breakpoints with visible keyboard focus states.

## Verification

Run the narrowest relevant checks after implementation:

- `pnpm run test:frontend`
- `pnpm run build`
- `git diff --check`

Use browser verification for representative shared-shell screens: one ERP role page, the module landing page, notifications/settings, and one shop-owner page. Confirm sidebar, logo, module card, status badge, focus state, and responsive layout.

## Risks and Mitigations

- Global blue overrides may affect customer-facing pages. Limit changes to ERP/shared shell selectors or semantic tokens, and verify non-ERP pages.
- Blue may encode business meaning in a few components. Preserve it only when it is a deliberate non-status meaning and document the exception; do not blindly replace map/location or product-color visuals.
- Existing dark-mode CSS may override new light tokens. Test both the default light shell and any supported dark context before completion.
- Broad class replacement can affect unrelated user changes. Edit only targeted files and inspect the diff before verification.
