# Shop Owner Monochrome Consistency Design

## Goal

Make the Shop Owner experience visually consistent in Light Mode. The canonical SoleSpace shell, module tabs, dashboard cards, section cards, and charts should use the approved black, white, and neutral-gray theme. Preserve the application's original Dark Mode.

## Scope

- Audit Shop Owner pages and ERP module pages rendered in Shop Owner owner mode.
- Change the canonical Shop Owner sidebar logo and active navigation styling from blue to black and white.
- Align active module tabs with the same black active treatment.
- Normalize major dashboard and section cards to the established neutral card appearance: white surface, neutral border, consistent radius, restrained shadow, black text, and gray icon badge.
- Remove decorative blue, green, purple, amber, and gradient treatments from card surfaces, card borders, card headings, and card icon badges in Light Mode.
- Convert charts across every account area already covered by the prior monochrome theme work—not only Shop Owner—to a monochrome palette. Use black as the primary series and neutral grays or dash patterns for additional series so data remains distinguishable.
- Keep semantic red, green, and amber only where color communicates an actual warning, success, error, or status.

## Theme Contract

### Light Mode

- Brand/logo text: `#111111`.
- Active navigation and active tabs: `#111111` background with white text and icons.
- Hovered navigation and tabs: light neutral gray with black text.
- Card surface: white.
- Card border: neutral gray equivalent to `gray-200`.
- Card shadow: the existing restrained `shadow-sm` treatment.
- Major card icon badge: light neutral gray background with black icon.
- Card titles and metric values: black or the existing darkest neutral.
- Charts: black primary data; gray secondary data; no blue, purple, green, or orange decorative chart palette.

### Dark Mode

Do not change existing Dark Mode colors, surfaces, borders, chart palettes, navigation states, or component behavior. Every Light Mode adjustment must preserve an explicit existing `dark:` variant or be scoped so it cannot affect Dark Mode.

## Implementation Approach

Use the smallest shared solution that fits the current architecture:

1. Update the canonical owner shell components for logo, navigation, focus, hover, and active states.
2. Reuse or introduce a minimal shared class/token contract for neutral Light Mode cards where multiple pages already share structure.
3. Apply targeted source changes to pages with hardcoded gradients, colored borders, icon badges, or ApexCharts color arrays.
4. Do not globally rewrite every semantic color utility. Status badges and alerts remain semantic.
5. Do not add dependencies or redesign page structure, content, data flow, or permissions.

## Acceptance Criteria

- The canonical Shop Owner `SoleSpace` logo is black in Light Mode.
- Shop Owner sidebar and module-tab active states are black with white content in Light Mode.
- Shop Owner dashboard/module cards present the same neutral visual family across Finance, Inventory, Logistics, Workforce, Procurement, and other accessible pages.
- Major card icons use black on light gray unless they represent a semantic status.
- Charts across all account areas covered by the prior monochrome theme are monochrome in Light Mode and remain readable when multiple series exist.
- Semantic warning/success/error statuses retain meaningful colors.
- No generic blue active, card, badge, focus, or chart treatment remains on the audited Light Mode surfaces.
- Dark Mode retains its pre-existing appearance and behavior.
- Existing unrelated working-tree changes are not staged, modified, or committed.
- Frontend tests, production build, and diff-hygiene checks pass; a fresh `public/build` is committed.

## Verification

- Run focused frontend tests for the canonical owner sidebar and affected dashboard components.
- Run `pnpm run test:frontend` when the local package-manager environment permits it.
- Run the production build and inspect representative Shop Owner pages in Light Mode and Dark Mode.
- Run `git diff --check` and confirm only intended source/build files are committed.
