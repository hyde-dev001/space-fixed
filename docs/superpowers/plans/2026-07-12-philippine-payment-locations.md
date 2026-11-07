# Philippine Payment Locations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Cavite-only payment location control with dependent province and city/municipality selectors covering every Philippine province plus Metro Manila.

**Architecture:** Bundle a PSGC-derived province hierarchy in one focused TypeScript module and expose normalization/look-up helpers from that module. Keep `payment.tsx`'s existing state and custom dropdown UI, but make province selection clear the dependent city and make all loading, saving, estimation, and checkout paths normalize the province/city pair together.

**Tech Stack:** React 18, TypeScript 5.7, Vite 7, Vitest 3, Tailwind CSS, Laravel/Inertia

## Global Constraints

- Data must cover every province, city, and municipality in the authoritative Philippine Standard Geographic Code, plus Metro Manila as a province-level UI option.
- The checkout must not make runtime location API calls.
- Add no npm or Composer dependency.
- Preserve existing desktop and address-sheet styling, spacing, scrolling, responsive behavior, saved-address behavior, shipping estimates, and checkout behavior.
- Keep the existing backend fields: `region`, `province`, `city`, `shipping_region`, `shipping_province`, and `shipping_city`.
- Barangay selection, postal-code inference, backend schema changes, and unrelated checkout refactoring remain out of scope.

---

## File Structure

- Create `resources/js/data/philippineLocations.ts`: static PSGC-derived hierarchy plus province/city normalization helpers.
- Create `resources/js/data/__tests__/philippineLocations.test.ts`: the single focused coverage and lookup test required by the design.
- Modify `resources/js/Pages/UserSide/Orders/payment.tsx`: replace Cavite constants and city-only behavior with dependent selectors in both existing form layouts.

### Task 1: Add the PSGC Location Hierarchy and Lookups

**Files:**
- Create: `resources/js/data/philippineLocations.ts`
- Create: `resources/js/data/__tests__/philippineLocations.test.ts`

**Interfaces:**
- Consumes: PSGC province and city/municipality names from the current Philippine Statistics Authority classification, cross-checked against the PSA province and summary tables.
- Produces: `ProvinceOption`, `PHILIPPINE_LOCATIONS`, `normalizeLocationKey(value)`, `normalizeProvinceSelection(value)`, `getCityMunicipalityOptions(province)`, and `normalizeCityMunicipalitySelection(province, value)`.

- [ ] **Step 1: Write the failing hierarchy and lookup test**

Create `resources/js/data/__tests__/philippineLocations.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import {
  PHILIPPINE_LOCATIONS,
  getCityMunicipalityOptions,
  normalizeCityMunicipalitySelection,
  normalizeProvinceSelection,
} from '../philippineLocations';

describe('Philippine location hierarchy', () => {
  it('contains unique, non-empty province and city/municipality choices', () => {
    expect(PHILIPPINE_LOCATIONS).toHaveLength(83); // 82 provinces + Metro Manila
    expect(new Set(PHILIPPINE_LOCATIONS.map(({ name }) => name)).size).toBe(83);

    for (const province of PHILIPPINE_LOCATIONS) {
      expect(province.citiesMunicipalities.length).toBeGreaterThan(0);
      expect(new Set(province.citiesMunicipalities).size).toBe(province.citiesMunicipalities.length);
    }
  });

  it('includes Metro Manila and representative locations nationwide', () => {
    expect(getCityMunicipalityOptions('Metro Manila')).toEqual(expect.arrayContaining([
      'City of Manila', 'Quezon City', 'Pateros',
    ]));
    expect(getCityMunicipalityOptions('Metro Manila')).toHaveLength(17);
    expect(getCityMunicipalityOptions('Batanes')).toContain('Basco');
    expect(getCityMunicipalityOptions('Cebu')).toContain('Cebu City');
    expect(getCityMunicipalityOptions('Davao del Sur')).toContain('Davao City');
  });

  it('normalizes saved province and city aliases within the selected province', () => {
    expect(normalizeProvinceSelection('NCR')).toBe('Metro Manila');
    expect(normalizeProvinceSelection('cavite')).toBe('Cavite');
    expect(normalizeCityMunicipalitySelection('Cavite', 'Dasmarinas')).toBe('Dasmariñas');
    expect(normalizeCityMunicipalitySelection('Cavite', 'City of Cavite')).toBe('Cavite City');
    expect(normalizeCityMunicipalitySelection('Metro Manila', 'Manila')).toBe('City of Manila');
    expect(normalizeCityMunicipalitySelection('Cebu', 'Dasmarinas')).toBe('');
  });
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```powershell
pnpm exec vitest run resources/js/data/__tests__/philippineLocations.test.ts
```

Expected: FAIL because `../philippineLocations` does not exist.

- [ ] **Step 3: Add the static hierarchy and minimal lookup helpers**

Create `resources/js/data/philippineLocations.ts` with the complete PSGC-derived data, alphabetized first by province-level display name and then by city/municipality display name. Use this exact public interface and helper implementation around the complete data array:

```ts
export interface ProvinceOption {
  name: string;
  aliases?: string[];
  citiesMunicipalities: string[];
  cityAliases?: Record<string, string[]>;
}

export const PHILIPPINE_LOCATIONS: ProvinceOption[] = [
  // The committed array contains the 82 PSA provinces in alphabetical order,
  // with every PSGC city and municipality nested under its province.
  {
    name: 'Metro Manila',
    aliases: ['NCR', 'National Capital Region'],
    citiesMunicipalities: [
      'Caloocan City', 'City of Manila', 'Las Piñas City', 'Makati City',
      'Malabon City', 'Mandaluyong City', 'Marikina City', 'Muntinlupa City',
      'Navotas City', 'Parañaque City', 'Pasay City', 'Pasig City', 'Pateros',
      'Quezon City', 'San Juan City', 'Taguig City', 'Valenzuela City',
    ],
    cityAliases: { 'City of Manila': ['Manila'] },
  },
];

export const normalizeLocationKey = (value?: string | null) => (value || '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/\./g, '')
  .replace(/\s+/g, ' ')
  .trim()
  .toLowerCase();

const provinceLookup = new Map<string, ProvinceOption>();

for (const province of PHILIPPINE_LOCATIONS) {
  for (const candidate of [province.name, ...(province.aliases || [])]) {
    provinceLookup.set(normalizeLocationKey(candidate), province);
  }
}

export const normalizeProvinceSelection = (value?: string | null) =>
  provinceLookup.get(normalizeLocationKey(value))?.name || '';

export const getCityMunicipalityOptions = (province?: string | null) =>
  provinceLookup.get(normalizeLocationKey(province))?.citiesMunicipalities || [];

export const normalizeCityMunicipalitySelection = (
  province: string | null | undefined,
  value: string | null | undefined,
) => {
  const provinceOption = provinceLookup.get(normalizeLocationKey(province));
  const cityKey = normalizeLocationKey(value);
  if (!provinceOption || !cityKey) return '';

  for (const city of provinceOption.citiesMunicipalities) {
    const candidates = [city, ...(provinceOption.cityAliases?.[city] || [])];
    if (candidates.some((candidate) => normalizeLocationKey(candidate) === cityKey)) return city;
  }

  return '';
};
```

Transcribe the 82 province objects from the current official PSA PSGC pages, not from memory. Preserve official names but omit UI-only suffixes such as `(Capital)` and administrative type labels. The PSA identifies PSGC as the official classification of regions, provinces, cities, municipalities, and barangays; use its current province and summary tables as the acceptance source. The exhaustive literal array is implementation data; the code block above defines its exact type, NCR representation, aliases, and executable behavior.

- [ ] **Step 4: Run the focused test and correct data discrepancies**

Run:

```powershell
pnpm exec vitest run resources/js/data/__tests__/philippineLocations.test.ts
```

Expected: PASS with 3 tests.

- [ ] **Step 5: Commit the hierarchy**

```powershell
git add resources/js/data/philippineLocations.ts resources/js/data/__tests__/philippineLocations.test.ts
git commit -m "feat: add Philippine location hierarchy"
```

If Git metadata remains unavailable in this workspace, skip only the commit commands and report that condition; do not skip tests or implementation.

### Task 2: Wire Dependent Selectors into Payment

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx:1-1655`

**Interfaces:**
- Consumes: all exports created by Task 1.
- Produces: province-aware saved-address restoration, shipping estimation, address persistence, and checkout payloads while preserving the existing state variable `shippingRegion` as the selected province.

- [ ] **Step 1: Replace the Cavite-only constants and normalizer**

Import the Task 1 interface:

```ts
import {
  PHILIPPINE_LOCATIONS,
  getCityMunicipalityOptions,
  normalizeCityMunicipalitySelection,
  normalizeProvinceSelection,
} from '@/data/philippineLocations';
```

Delete `CityOption`, `DEFAULT_SHIPPING_REGION`, `PH_CITY_OPTIONS`, `normalizeCityKey`, `CITY_OPTION_LOOKUP`, and `normalizeCitySelection`. Immediately after the state declarations, derive the dependent choices:

```ts
const cityMunicipalityOptions = getCityMunicipalityOptions(shippingRegion);
```

- [ ] **Step 2: Add province selection and make city selection province-aware**

Add desktop and sheet province dropdown refs/open state next to the existing city equivalents:

```ts
const desktopProvinceDropdownRef = useRef<HTMLDivElement | null>(null);
const sheetProvinceDropdownRef = useRef<HTMLDivElement | null>(null);
const [isDesktopProvinceDropdownOpen, setIsDesktopProvinceDropdownOpen] = useState(false);
const [isSheetProvinceDropdownOpen, setIsSheetProvinceDropdownOpen] = useState(false);
```

Replace `handleCityChange` with:

```ts
const handleProvinceChange = (province: string) => {
  setShippingRegion(normalizeProvinceSelection(province));
  setShippingCity('');
  setShippingEstimate(null);
  setShippingEstimateReason(null);
  setIsShippingEstimateLoading(false);
  setIsDesktopProvinceDropdownOpen(false);
  setIsSheetProvinceDropdownOpen(false);
  setIsDesktopCityDropdownOpen(false);
  setIsSheetCityDropdownOpen(false);
};

const handleCityChange = (city: string) => {
  const selectedCity = normalizeCityMunicipalitySelection(shippingRegion, city);
  setShippingCity(selectedCity);
  setShippingEstimate(null);
  setShippingEstimateReason(null);
  setIsShippingEstimateLoading(Boolean(selectedCity));
  setIsDesktopCityDropdownOpen(false);
  setIsSheetCityDropdownOpen(false);
};
```

Extend the existing outside-click effect so each province ref closes its matching dropdown under the same conditions already used for city dropdowns.

- [ ] **Step 3: Normalize province/city pairs in every load and save path**

Use this exact pairing whenever session checkout data, `applySelectedAddress`, or `handleEditAddressFromList` restores address state:

```ts
const selectedProvince = normalizeProvinceSelection(addr.province || addr.region);
const selectedCity = normalizeCityMunicipalitySelection(selectedProvince, addr.city);
setShippingRegion(selectedProvince || addr.province || addr.region || '');
setShippingCity(selectedCity || addr.city || '');
```

For checkout data, replace `addr` with `data` and use `data.shipping_province || data.shipping_region` plus `data.shipping_city`.

In address persistence and checkout payloads, use the selected pair directly:

```ts
region: shippingRegion,
province: shippingRegion,
city: normalizeCityMunicipalitySelection(shippingRegion, shippingCity) || shippingCity,
```

Set both checkout fields instead of nulling the province:

```ts
shipping_region: shippingRegion,
shipping_province: shippingRegion,
shipping_city: normalizeCityMunicipalitySelection(shippingRegion, shippingCity) || shippingCity,
```

Replace every remaining `normalizeCitySelection(shippingCity)` call with `normalizeCityMunicipalitySelection(shippingRegion, shippingCity)`. Remove every fallback to `DEFAULT_SHIPPING_REGION`.

- [ ] **Step 4: Add the province selector to both existing UI locations**

In both the address-sheet form and desktop form, insert a province custom dropdown immediately before the existing city control. Reuse the city dropdown classes exactly. Its button displays `shippingRegion || 'Select Province'`; its list maps `PHILIPPINE_LOCATIONS`; its selection calls `handleProvinceChange(province.name)`.

Use these semantics in both copies:

```tsx
<button
  type="button"
  aria-label="Province"
  aria-haspopup="listbox"
  aria-expanded={isProvinceDropdownOpen}
>
  <span>{shippingRegion || 'Select Province'}</span>
  <span>▾</span>
</button>
```

Update the city button in both copies:

```tsx
<button
  type="button"
  disabled={!shippingRegion}
  aria-label="City/Municipality"
  aria-haspopup="listbox"
  aria-expanded={isCityDropdownOpen}
>
  <span>{shippingCity || 'Select City/Municipality'}</span>
  <span>▾</span>
</button>
```

Map `cityMunicipalityOptions` as strings instead of `PH_CITY_OPTIONS`, compare `shippingCity === city`, and call `handleCityChange(city)`. Add the disabled classes `disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400` without changing the existing enabled-state classes. Change visible desktop label `City` to `City/Municipality`.

- [ ] **Step 5: Run the focused data test and production build**

Run:

```powershell
pnpm exec vitest run resources/js/data/__tests__/philippineLocations.test.ts
pnpm run build
```

Expected: the focused test reports 3 passing tests, and Vite completes a production build with exit code 0.

- [ ] **Step 6: Manually verify the preserved flow**

Run `pnpm run dev`, open the payment page, and verify:

- desktop and address-sheet forms show Province before City/Municipality;
- City/Municipality is disabled before province selection;
- selecting Cavite shows Cavite's cities and municipalities;
- changing from Cavite to Cebu clears the selected city and shows only Cebu entries;
- Metro Manila shows sixteen cities plus Pateros;
- selecting a saved address restores its province and city;
- shipping estimation still starts after city selection;
- saved-address and checkout requests include the selected province and city in their existing fields.

- [ ] **Step 7: Commit the Payment integration**

```powershell
git add resources/js/Pages/UserSide/Orders/payment.tsx
git commit -m "feat: add dependent Philippine location selectors"
```

If Git metadata remains unavailable, skip only the commit command and report it.
