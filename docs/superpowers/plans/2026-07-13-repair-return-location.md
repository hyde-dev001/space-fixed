# Repair Return Location Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the repair return-address Cavite-only City selector with dependent nationwide Province and City/Municipality selectors matching the payment flow.

**Architecture:** Reuse the existing Philippine location dataset and normalization helpers directly in `RepairProcess.tsx`. Keep the existing `returnRegion`/`returnCity` state and `return_region`/`return_city` request fields, so the change remains frontend-only.

**Tech Stack:** React 18, TypeScript, Inertia, Tailwind CSS, Vitest

## Global Constraints

- Change only the Return Address flow; pickup fields stay unchanged.
- Do not modify the payment page, shared location dataset, backend schema, or database.
- Do not add a component, abstraction, dependency, or package.
- Preserve custom-listbox accessibility: labels, expanded/selected state, arrow navigation, Escape handling, and click-outside closing.

---

## File Structure

- Create `resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts`: focused source-level integration contract, following the existing payment location integration test.
- Modify `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`: import the shared location data, normalize saved/submitted values, and render dependent selectors.

### Task 1: Dependent return Province and City/Municipality selectors

**Files:**
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts`
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`

**Interfaces:**
- Consumes: `PHILIPPINE_LOCATIONS`, `getCityMunicipalityOptions(province?: string | null): string[]`, `normalizeProvinceSelection(value?: string | null): string`, and `normalizeCityMunicipalitySelection(province, value): string` from `resources/js/data/philippineLocations.ts`.
- Produces: unchanged `formData.returnRegion` and `formData.returnCity` values submitted as `return_region` and `return_city`.

- [ ] **Step 1: Write the failing integration contract**

Create `resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts`:

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const repairProcessSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Repairs/RepairProcess.tsx'),
  'utf8',
);

describe('repair process return-location integration', () => {
  it('uses dependent Philippine province and municipality selectors', () => {
    expect(repairProcessSource).toContain('PHILIPPINE_LOCATIONS');
    expect(repairProcessSource).toContain('getCityMunicipalityOptions(formData.returnRegion)');
    expect(repairProcessSource).toContain('normalizeProvinceSelection');
    expect(repairProcessSource).toContain('normalizeCityMunicipalitySelection');
    expect(repairProcessSource).toContain('City/Municipality');
    expect(repairProcessSource).toContain('!formData.returnRegion');
    expect(repairProcessSource).not.toContain('PH_CITY_OPTIONS');
    expect(repairProcessSource).not.toContain('DEFAULT_SHIPPING_REGION');
  });
});
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts
```

Expected: FAIL because `RepairProcess.tsx` still contains `PH_CITY_OPTIONS` and does not import or use the shared nationwide hierarchy.

- [ ] **Step 3: Replace local Cavite data with shared location imports**

Add this import after the existing utility imports:

```tsx
import {
  PHILIPPINE_LOCATIONS,
  getCityMunicipalityOptions,
  normalizeCityMunicipalitySelection,
  normalizeProvinceSelection,
} from '@/data/philippineLocations';
```

Delete `DEFAULT_SHIPPING_REGION`, `CityOption`, `PH_CITY_OPTIONS`, `normalizeCityKey`, `CITY_OPTION_LOOKUP`, and `normalizeCitySelection`. Keep `getEffectivePackagePrice` as the first local helper after the interfaces.

- [ ] **Step 4: Add dependent selector state, refs, and keyboard handling**

Immediately after the `formData` state, derive the valid municipality list and add province dropdown state/ref alongside the existing city dropdown:

```tsx
const returnCityMunicipalityOptions = getCityMunicipalityOptions(formData.returnRegion);
const [isReturnProvinceDropdownOpen, setIsReturnProvinceDropdownOpen] = useState(false);
const [isReturnCityDropdownOpen, setIsReturnCityDropdownOpen] = useState(false);
const returnProvinceDropdownRef = useRef<HTMLDivElement | null>(null);
const returnCityDropdownRef = useRef<HTMLDivElement | null>(null);
```

Add province click-outside handling next to the city handling:

```tsx
if (returnProvinceDropdownRef.current && !returnProvinceDropdownRef.current.contains(event.target as Node)) {
  setIsReturnProvinceDropdownOpen(false);
}

if (returnCityDropdownRef.current && !returnCityDropdownRef.current.contains(event.target as Node)) {
  setIsReturnCityDropdownOpen(false);
}
```

Add the payment page's minimal keyboard behavior before the location change handlers:

```tsx
const handleDropdownTriggerKeyDown = (
  event: React.KeyboardEvent<HTMLButtonElement>,
  setOpen: React.Dispatch<React.SetStateAction<boolean>>,
  containerRef: React.RefObject<HTMLDivElement | null>,
) => {
  if (event.key === 'Escape') {
    setOpen(false);
    return;
  }
  if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

  event.preventDefault();
  setOpen(true);
  requestAnimationFrame(() => {
    const options = containerRef.current?.querySelectorAll<HTMLElement>('[role="option"]');
    options?.[event.key === 'ArrowUp' ? options.length - 1 : 0]?.focus();
  });
};

const handleListboxKeyDown = (
  event: React.KeyboardEvent<HTMLDivElement>,
  setOpen: React.Dispatch<React.SetStateAction<boolean>>,
  containerRef: React.RefObject<HTMLDivElement | null>,
) => {
  if (event.key === 'Escape') {
    event.preventDefault();
    setOpen(false);
    containerRef.current?.querySelector<HTMLButtonElement>('button')?.focus();
    return;
  }
  if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

  event.preventDefault();
  const options = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('[role="option"]'));
  const currentIndex = options.indexOf(document.activeElement as HTMLElement);
  const offset = event.key === 'ArrowDown' ? 1 : -1;
  options[(currentIndex + offset + options.length) % options.length]?.focus();
};
```

- [ ] **Step 5: Normalize restored values and location changes**

Replace saved-location normalization with:

```tsx
const savedReturnRegion = normalizeProvinceSelection(parsed.returnRegion);
const savedReturnCity = normalizeCityMunicipalitySelection(savedReturnRegion, parsed.returnCity);
```

Keep these exact values in the restored state:

```tsx
returnCity: savedReturnCity,
returnRegion: savedReturnRegion,
```

Replace `handleReturnCityChange` and delete `getCityLabel`; use:

```tsx
const handleReturnProvinceChange = (province: string) => {
  setFormData((prev) => ({
    ...prev,
    returnRegion: normalizeProvinceSelection(province),
    returnCity: '',
  }));
  setIsReturnProvinceDropdownOpen(false);
  setIsReturnCityDropdownOpen(false);
};

const handleReturnCityChange = (city: string) => {
  setFormData((prev) => ({
    ...prev,
    returnCity: normalizeCityMunicipalitySelection(prev.returnRegion, city),
  }));
  setIsReturnCityDropdownOpen(false);
};
```

- [ ] **Step 6: Require and submit a valid province/city pair**

Make the courier validation block require both fields:

```tsx
const missingReturnFields =
  !formData.returnAddressLine ||
  !formData.returnBarangay ||
  !formData.returnRegion ||
  !formData.returnCity ||
  !normalizedReturnPostalCode;
```

Replace submission normalization with:

```tsx
const returnRegion = normalizeProvinceSelection(formData.returnRegion);
const returnCity = normalizeCityMunicipalitySelection(returnRegion, formData.returnCity);
const normalizedReturnPostalCode = formData.returnPostalCode.replace(/\D/g, '');

submitFormData.append('return_address_line', formData.returnAddressLine);
submitFormData.append('return_barangay', formData.returnBarangay);
submitFormData.append('return_city', returnCity);
submitFormData.append('return_region', returnRegion);
submitFormData.append('return_postal_code', normalizedReturnPostalCode);
```

- [ ] **Step 7: Render the linked selectors**

Replace the current City block with a three-column Province, City/Municipality, and Postal code group. The two selector blocks must use:

```tsx
<div className="grid grid-cols-1 gap-4 md:grid-cols-3">
  <div>
    <label className="block text-sm font-medium text-black mb-2">Province</label>
    <div ref={returnProvinceDropdownRef} className="relative">
      <button
        type="button"
        onClick={() => setIsReturnProvinceDropdownOpen((prev) => !prev)}
        onKeyDown={(event) => handleDropdownTriggerKeyDown(event, setIsReturnProvinceDropdownOpen, returnProvinceDropdownRef)}
        className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left"
        aria-label="Province"
        aria-haspopup="listbox"
        aria-expanded={isReturnProvinceDropdownOpen}
      >
        <span className={formData.returnRegion ? 'text-black' : 'text-gray-500'}>
          {formData.returnRegion || 'Select Province'}
        </span>
        <span className={`text-gray-500 transition-transform ${isReturnProvinceDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
      </button>
      {isReturnProvinceDropdownOpen && (
        <div
          role="listbox"
          onKeyDown={(event) => handleListboxKeyDown(event, setIsReturnProvinceDropdownOpen, returnProvinceDropdownRef)}
          className="hide-scrollbar absolute left-0 right-0 top-full z-30 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg"
        >
          {PHILIPPINE_LOCATIONS.map((province) => (
            <button
              key={province.name}
              type="button"
              role="option"
              aria-selected={formData.returnRegion === province.name}
              onClick={() => handleReturnProvinceChange(province.name)}
              className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${formData.returnRegion === province.name ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
            >
              {province.name}
            </button>
          ))}
        </div>
      )}
    </div>
  </div>

  <div>
    <label className="block text-sm font-medium text-black mb-2">City/Municipality</label>
    <div ref={returnCityDropdownRef} className="relative">
      <button
        type="button"
        onClick={() => setIsReturnCityDropdownOpen((prev) => !prev)}
        onKeyDown={(event) => handleDropdownTriggerKeyDown(event, setIsReturnCityDropdownOpen, returnCityDropdownRef)}
        disabled={!formData.returnRegion}
        className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
        aria-label="City/Municipality"
        aria-haspopup="listbox"
        aria-expanded={isReturnCityDropdownOpen}
      >
        <span className={formData.returnCity ? 'text-black' : 'text-gray-500'}>
          {formData.returnCity || 'Select City/Municipality'}
        </span>
        <span className={`text-gray-500 transition-transform ${isReturnCityDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
      </button>
      {isReturnCityDropdownOpen && (
        <div
          role="listbox"
          onKeyDown={(event) => handleListboxKeyDown(event, setIsReturnCityDropdownOpen, returnCityDropdownRef)}
          className="hide-scrollbar absolute left-0 right-0 top-full z-30 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg"
        >
          {returnCityMunicipalityOptions.map((city) => (
            <button
              key={city}
              type="button"
              role="option"
              aria-selected={formData.returnCity === city}
              onClick={() => handleReturnCityChange(city)}
              className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${formData.returnCity === city ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
            >
              {city}
            </button>
          ))}
        </div>
      )}
    </div>
  </div>

  <div>
    <label className="block text-sm font-medium text-black mb-2">Postal code</label>
    <input
      type="text"
      name="returnPostalCode"
      value={formData.returnPostalCode}
      onChange={handleInputChange}
      inputMode="numeric"
      pattern="[0-9]*"
      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
      placeholder="Postal code"
      required
    />
  </div>
</div>
```

- [ ] **Step 8: Run focused and shared-location tests and verify GREEN**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts resources/js/data/__tests__/philippineLocations.test.ts
```

Expected: both test files PASS.

- [ ] **Step 9: Run the production build**

Run:

```bash
pnpm build
```

Expected: Vite exits with code 0 and reports a successful production build.

- [ ] **Step 10: Commit only the repair selector files**

```bash
git add resources/js/Pages/UserSide/Repairs/RepairProcess.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts
git commit -m "feat: add repair return province selector"
```

