# Repair Return Location Design

## Scope

Update only the Return Address section of `RepairProcess.tsx`. Replace the Cavite-only City selector with linked Province and City/Municipality selectors matching the payment page's behavior and styling.

## Data and behavior

- Reuse `PHILIPPINE_LOCATIONS`, `getCityMunicipalityOptions`, `normalizeProvinceSelection`, and `normalizeCityMunicipalitySelection` from `resources/js/data/philippineLocations.ts`.
- Store the selected province in the existing `returnRegion` field and the selected city or municipality in `returnCity`.
- Clear `returnCity` whenever `returnRegion` changes.
- Disable City/Municipality until a province is selected, then show only locations belonging to that province.
- Normalize restored local-storage values. Preserve valid saved province/city pairs and clear invalid combinations.
- Keep the existing `return_region` and `return_city` submission fields; no backend or database changes are required.

## Validation and accessibility

- Require Province and City/Municipality for courier return delivery, in addition to the existing address fields.
- Keep listbox labels, expanded state, selected state, keyboard navigation, and click-outside closing behavior consistent with the payment selectors.

## Verification

- Add a focused test for the repair page's use of province-dependent municipality selection where the current frontend test setup supports it.
- Run the focused test and the project's TypeScript/build verification relevant to `RepairProcess.tsx`.

## Non-goals

- Do not change pickup address fields.
- Do not change the payment page, location dataset, backend request schema, or database.
- Do not add a new component or dependency.
