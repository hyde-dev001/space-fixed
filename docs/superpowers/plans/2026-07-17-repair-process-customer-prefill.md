# Repair Process Customer Prefill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prefill empty repair contact, pickup, and return fields from the authenticated user and default shipping address without overwriting saved repair checkout information.

**Architecture:** Add one pure merge helper so priority and field mapping are independently testable. `RepairProcess.tsx` restores local storage as it already does, then fetches `/api/user/addresses` for authenticated users and applies the helper with a functional state update. No backend change is required.

**Tech Stack:** React 18, TypeScript, Inertia, Vitest, Vite.

## Global Constraints

- Saved `repair_process_checkout_info` values have priority.
- Account and default-address values fill empty fields only.
- Prefill both pickup and return structured address fields without selecting delivery methods.
- Guests and failed address requests retain current manual behavior.
- Add no endpoint or dependency.
- Update `public/build` because Laravel serves the compiled bundle when `public/hot` is absent.

---

### Task 1: Add and integrate repair customer prefill

**Files:**
- Create: `resources/js/Pages/UserSide/Repairs/repairProcessPrefill.ts`
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/repairProcessPrefill.test.ts`
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx:60-68,219-238,381-411`
- Modify: `public/build/manifest.json` and generated `public/build/assets/*` via `npm.cmd run build`

**Interfaces:**
- Consumes: `mergeRepairProcessPrefill(current, user, address)` where every argument is a plain object.
- Produces: a copy of the current form with only empty contact/pickup/return fields filled.

- [ ] **Step 1: Write the failing helper test**

```ts
import { describe, expect, it } from 'vitest';
import { mergeRepairProcessPrefill } from '../repairProcessPrefill';

describe('mergeRepairProcessPrefill', () => {
  it('fills empty contact and delivery fields without replacing saved values', () => {
    const result = mergeRepairProcessPrefill(
      { customerName: 'Saved Name', email: '', phone: '', pickupAddressLine: '', pickupBarangay: '', pickupCity: '', pickupRegion: '', pickupPostalCode: '', returnAddressLine: 'Saved Return', returnBarangay: '', returnCity: '', returnRegion: '', returnPostalCode: '' },
      { name: 'Account Name', email: 'user@example.com', phone: '09171234567' },
      { address_line: '123 Rizal St', barangay: 'Ermita', city: 'Manila', province: 'Metro Manila', region: 'NCR', postal_code: '1000' },
    );

    expect(result).toMatchObject({
      customerName: 'Saved Name', email: 'user@example.com', phone: '09171234567',
      pickupAddressLine: '123 Rizal St', pickupBarangay: 'Ermita', pickupCity: 'Manila', pickupRegion: 'Metro Manila', pickupPostalCode: '1000',
      returnAddressLine: 'Saved Return', returnBarangay: 'Ermita', returnCity: 'Manila', returnRegion: 'Metro Manila', returnPostalCode: '1000',
    });
  });
});
```

- [ ] **Step 2: Run the test and verify the red state**

Run: `npm.cmd run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/repairProcessPrefill.test.ts`

Expected: FAIL because `repairProcessPrefill.ts` does not exist.

- [ ] **Step 3: Implement the minimal merge helper**

```ts
const emptyFallback = (current: unknown, fallback: unknown) => String(current || fallback || '');

export const mergeRepairProcessPrefill = (current: Record<string, any>, user?: Record<string, any> | null, address?: Record<string, any> | null) => {
  const province = address?.province || address?.region || '';
  return {
    ...current,
    customerName: emptyFallback(current.customerName, user?.name),
    email: emptyFallback(current.email, user?.email),
    phone: emptyFallback(current.phone, user?.phone),
    pickupAddressLine: emptyFallback(current.pickupAddressLine, address?.address_line),
    pickupBarangay: emptyFallback(current.pickupBarangay, address?.barangay),
    pickupCity: emptyFallback(current.pickupCity, address?.city),
    pickupRegion: emptyFallback(current.pickupRegion, province),
    pickupPostalCode: emptyFallback(current.pickupPostalCode, address?.postal_code),
    returnAddressLine: emptyFallback(current.returnAddressLine, address?.address_line),
    returnBarangay: emptyFallback(current.returnBarangay, address?.barangay),
    returnCity: emptyFallback(current.returnCity, address?.city),
    returnRegion: emptyFallback(current.returnRegion, province),
    returnPostalCode: emptyFallback(current.returnPostalCode, address?.postal_code),
  };
};
```

- [ ] **Step 4: Integrate authenticated prefill after local-storage restoration**

Add `phone?: string` to the `auth.user` type and import the helper. Add an effect keyed by `authUser?.id`:

```tsx
useEffect(() => {
  if (!authUser) return;
  let cancelled = false;

  setFormData((current) => mergeRepairProcessPrefill(current, authUser));
  fetch('/api/user/addresses', { headers: { Accept: 'application/json' }, credentials: 'include' })
    .then((response) => response.ok ? response.json() : null)
    .then((data) => {
      if (cancelled) return;
      const addresses = Array.isArray(data?.addresses) ? data.addresses : [];
      const defaultAddress = addresses.find((address: any) => address.is_default) || addresses[0];
      if (defaultAddress) setFormData((current) => mergeRepairProcessPrefill(current, authUser, defaultAddress));
    })
    .catch(() => {});

  return () => { cancelled = true; };
}, [authUser?.id]);
```

- [ ] **Step 5: Run focused tests**

Run: `npm.cmd run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/repairProcessPrefill.test.ts resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts`

Expected: both files pass.

- [ ] **Step 6: Build and verify the served bundle**

Run: `npm.cmd run build`

Expected: Vite succeeds. Confirm `public/build/manifest.json` points to a new `RepairProcess-*.js` containing `/api/user/addresses`.

- [ ] **Step 7: Commit source, test, and served bundle**

```bash
git add resources/js/Pages/UserSide/Repairs/RepairProcess.tsx resources/js/Pages/UserSide/Repairs/repairProcessPrefill.ts resources/js/Pages/UserSide/Repairs/__tests__/repairProcessPrefill.test.ts public/build
git commit -m "feat: prefill repair customer information"
```
