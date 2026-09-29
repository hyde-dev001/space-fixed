# Customer Registration Map Address Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a compact Philippines-wide Leaflet address picker to customer registration and persist its result as the customer's default shipping address for automatic payment prefill.

**Architecture:** Keep map state and Nominatim calls local to the existing registration page, reusing the Shop Owner Leaflet pattern without refactoring that working flow. Submit structured address fields with the existing multipart request; the registration controller creates `User` and `UserAddress` atomically. `payment.tsx` already loads the authenticated user's default address and customer identity, so verify and reuse that path instead of duplicating it.

**Tech Stack:** React 18, TypeScript, Leaflet 1.9, OpenStreetMap/Nominatim, Inertia, Laravel, PHPUnit.

## Global Constraints

- Preserve the current registration card width, three-step flow, input styling, and responsive layout.
- Accept map locations only within the Philippines.
- Keep the address text manually editable while retaining selected coordinates.
- Use the installed `leaflet` dependency; add no package.
- Do not add shop-only geofence toggle, radius, circle, slider, or save-settings controls.
- Persist user and default shipping address in one database transaction.

---

## File Map

- `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`: focused registration persistence and validation coverage.
- `app/Http/Controllers/UserController.php`: validate structured location fields and atomically create the user plus default address.
- `resources/js/Pages/UserSide/Auth/Register.tsx`: compact search/GPS/Leaflet UI, location state, geocoding, validation, and request payload.
- `resources/js/Pages/UserSide/Orders/payment.tsx`: no planned edit; existing `loadSavedAddress` and `applySelectedAddress` are the integration point to verify.

### Task 1: Persist the registration address atomically

**Files:**
- Create: `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`
- Modify: `app/Http/Controllers/UserController.php:164-260`

**Interfaces:**
- Consumes multipart fields `address_region`, `address_province`, `address_city`, `address_barangay`, `address_postal_code`, `address_latitude`, and `address_longitude`.
- Produces one `UserAddress` related to the new user with `is_default = true`.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\UserSide;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerRegistrationAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_default_shipping_address(): void
    {
        Storage::fake('public');

        $response = $this->post('/user/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'age' => 25,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'address' => '123 Rizal Street, Ermita, Manila',
            'address_region' => 'National Capital Region',
            'address_province' => 'Metro Manila',
            'address_city' => 'Manila',
            'address_barangay' => 'Ermita',
            'address_postal_code' => '1000',
            'address_latitude' => 14.5832,
            'address_longitude' => 120.9822,
            'valid_id' => UploadedFile::fake()->image('valid-id.jpg'),
        ]);

        $response->assertRedirect(route('verification.notice'));
        $userId = (int) auth('web')->id();

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'address' => '123 Rizal Street, Ermita, Manila',
        ]);
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $userId,
            'name' => 'Juan Dela Cruz',
            'phone' => '09171234567',
            'region' => 'National Capital Region',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'postal_code' => '1000',
            'address_line' => '123 Rizal Street, Ermita, Manila',
            'latitude' => 14.5832,
            'longitude' => 120.9822,
            'is_default' => true,
        ]);
    }

    public function test_registration_requires_a_complete_map_location(): void
    {
        Storage::fake('public');

        $this->post('/user/register', [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com', 'phone' => '09171234567', 'age' => 25,
            'password' => 'Password1', 'password_confirmation' => 'Password1',
            'address' => 'Typed address only',
            'valid_id' => UploadedFile::fake()->image('valid-id.jpg'),
        ])->assertSessionHasErrors([
            'address_region', 'address_province', 'address_city', 'address_barangay',
            'address_latitude', 'address_longitude',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'juan@example.com']);
    }
}
```

- [ ] **Step 2: Run the test and confirm the red state**

Run: `php artisan test tests/Feature/UserSide/CustomerRegistrationAddressTest.php`

Expected: the default-address assertion fails and the missing location fields are not rejected.

- [ ] **Step 3: Add validation and atomic persistence**

Add `use App\Models\UserAddress;` and `use Illuminate\Support\Facades\DB;` to `UserController.php`. Extend the validator with:

```php
'address_region' => 'required|string|max:255',
'address_province' => 'required|string|max:255',
'address_city' => 'required|string|max:255',
'address_barangay' => 'required|string|max:255',
'address_postal_code' => 'nullable|string|max:10',
'address_latitude' => 'required|numeric|between:4.5,21.5',
'address_longitude' => 'required|numeric|between:116,127',
```

Replace the standalone `User::create(...)` with:

```php
$user = DB::transaction(function () use ($validated, $validIdPath) {
    $user = User::create([
        'first_name' => $validated['first_name'],
        'last_name' => $validated['last_name'],
        'name' => $validated['first_name'].' '.$validated['last_name'],
        'email' => $validated['email'],
        'phone' => $validated['phone'],
        'age' => $validated['age'],
        'password' => Hash::make($validated['password']),
        'address' => $validated['address'],
        'status' => 'active',
        'valid_id_path' => $validIdPath,
    ]);

    UserAddress::create([
        'user_id' => $user->id,
        'name' => $user->name,
        'phone' => $user->phone,
        'region' => $validated['address_region'],
        'province' => $validated['address_province'],
        'city' => $validated['address_city'],
        'barangay' => $validated['address_barangay'],
        'postal_code' => $validated['address_postal_code'] ?? null,
        'address_line' => $validated['address'],
        'latitude' => $validated['address_latitude'],
        'longitude' => $validated['address_longitude'],
        'is_default' => true,
    ]);

    return $user;
});
```

Add friendly messages for each required structured field and invalid coordinate. Keep all existing user validation and valid-ID handling intact.

- [ ] **Step 4: Run the focused test**

Run: `php artisan test tests/Feature/UserSide/CustomerRegistrationAddressTest.php`

Expected: 2 tests pass.

- [ ] **Step 5: Commit the backend slice**

```bash
git add app/Http/Controllers/UserController.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php
git commit -m "feat: save registration address as default"
```

### Task 2: Add the compact Philippines address picker

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/Register.tsx:1-500,638-652`

**Interfaces:**
- Consumes Nominatim search/reverse responses and browser `navigator.geolocation`.
- Produces the multipart fields defined in Task 1.

- [ ] **Step 1: Add typed location state and Philippines bounds**

Import `useRef`, then add:

```tsx
type AddressLocation = {
  region: string;
  province: string;
  city: string;
  barangay: string;
  postalCode: string;
  latitude: number | null;
  longitude: number | null;
};

const PHILIPPINES_CENTER: [number, number] = [12.8797, 121.774];
const isInPhilippines = (lat: number, lng: number) => lat >= 4.5 && lat <= 21.5 && lng >= 116 && lng <= 127;
```

Inside `Register`, add `addressLocation`, `addressSearch`, `geoError`, `isSearching`, `gettingGPS`, `mapRef`, `leafletMapRef`, and `markerRef`. Initialize location strings empty and coordinates null.

- [ ] **Step 2: Add one Nominatim response mapper**

```tsx
const applyNominatimLocation = (result: any) => {
  const lat = Number(result.lat);
  const lng = Number(result.lon);
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || !isInPhilippines(lat, lng)) {
    setGeoError('Please select an address within the Philippines.');
    return false;
  }

  const address = result.address || {};
  const city = address.city || address.municipality || address.town || address.village || '';
  const barangay = address.suburb || address.quarter || address.neighbourhood || address.village || '';
  const province = address.province || address.state || '';
  const region = address.region || address.state || province;
  if (!region || !province || !city || !barangay) {
    setGeoError('Move the pin or search again so region, city, and barangay can be identified.');
    return false;
  }

  setAddressLocation({
    region,
    province,
    city,
    barangay,
    postalCode: address.postcode || '',
    latitude: lat,
    longitude: lng,
  });
  setFormData((previous) => ({ ...previous, address: result.display_name || previous.address }));
  setAddressSearch(result.display_name || '');
  setGeoError('');
  return true;
};
```

- [ ] **Step 3: Initialize Leaflet only while step 2 is visible**

Use the existing Shop Owner dynamic `import('leaflet')`, icon URLs, OpenStreetMap tile layer, and cleanup pattern. Create only one draggable marker—no circle. On map click or marker `dragend`, fetch:

```tsx
const reverseGeocode = async (lat: number, lng: number) => {
  const response = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&addressdetails=1`);
  if (!response.ok) throw new Error('Address lookup failed');
  return applyNominatimLocation(await response.json());
};
```

After successful reverse geocoding, move the marker and center the map. Remove the Leaflet map in effect cleanup so navigating between registration steps does not duplicate map instances.

- [ ] **Step 4: Implement Search and GPS**

```tsx
const handleAddressSearch = async () => {
  const query = addressSearch.trim();
  if (!query) return setGeoError('Enter an address to search.');
  setIsSearching(true);
  setGeoError('');
  try {
    const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&countrycodes=ph&limit=1&q=${encodeURIComponent(query)}`);
    const [result] = await response.json();
    if (!result || !applyNominatimLocation(result)) return setGeoError((value) => value || 'No Philippine address found.');
  } catch {
    setGeoError('Address search is unavailable. Please try again.');
  } finally {
    setIsSearching(false);
  }
};

const handleUseMyGPS = () => {
  if (!navigator.geolocation) return setGeoError('Geolocation is not supported by your browser.');
  setGettingGPS(true);
  setGeoError('');
  navigator.geolocation.getCurrentPosition(
    async ({ coords }) => {
      try { await reverseGeocode(coords.latitude, coords.longitude); }
      catch { setGeoError('Could not identify your GPS address.'); }
      finally { setGettingGPS(false); }
    },
    () => { setGeoError('Could not get your location. Please allow location access.'); setGettingGPS(false); },
    { enableHighAccuracy: true },
  );
};
```

- [ ] **Step 5: Require map data in step validation and submit it**

Add an `addressLocation` error when coordinates or required structured fields are absent. Map that error to step 2 in `getFirstInvalidStep`. Append:

```tsx
payload.append('address_region', addressLocation.region);
payload.append('address_province', addressLocation.province);
payload.append('address_city', addressLocation.city);
payload.append('address_barangay', addressLocation.barangay);
payload.append('address_postal_code', addressLocation.postalCode);
payload.append('address_latitude', String(addressLocation.latitude));
payload.append('address_longitude', String(addressLocation.longitude));
```

Map backend structured-field errors to `addressLocation` so failures return the user to step 2.

- [ ] **Step 6: Render the compact controls without widening the card**

Immediately below the existing editable Address input, render a vertical mobile layout and `sm:flex` search row using existing colors and rounded styles:

```tsx
<div className="mt-3 space-y-2">
  <Label htmlFor="addressSearch" className="text-[12px] font-medium text-gray-700">Search Address</Label>
  <div className="flex flex-col gap-2 sm:flex-row">
    <input id="addressSearch" value={addressSearch} onChange={(event) => setAddressSearch(event.target.value)}
      onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); void handleAddressSearch(); } }}
      placeholder="e.g. 123 Rizal St, Makati" className="h-10 min-w-0 flex-1 rounded-xl border border-gray-200 bg-[#f8fafc] px-3 text-[12px]" />
    <button type="button" onClick={handleAddressSearch} disabled={isSearching}
      className="h-10 rounded-xl bg-blue-600 px-4 text-[12px] font-semibold text-white disabled:opacity-60">
      {isSearching ? 'Searching...' : 'Search'}
    </button>
    <button type="button" onClick={handleUseMyGPS} disabled={gettingGPS}
      className="h-10 rounded-xl border border-blue-600 px-3 text-[12px] font-semibold text-blue-600 disabled:opacity-60">
      {gettingGPS ? 'Locating...' : 'Use My GPS'}
    </button>
  </div>
  <p className="text-[11px] text-gray-500">Drag the pin or click the map to adjust.</p>
  <div ref={mapRef} className="h-44 w-full overflow-hidden rounded-xl border border-gray-200" />
  {geoError && <p className="text-xs text-red-600">{geoError}</p>}
</div>
```

- [ ] **Step 7: Run frontend verification**

Run: `pnpm build`

Expected: Vite build succeeds with no TypeScript error in `Register.tsx` or `payment.tsx`.

- [ ] **Step 8: Commit the frontend slice**

```bash
git add resources/js/Pages/UserSide/Auth/Register.tsx
git commit -m "feat: add registration address map"
```

### Task 3: Verify payment prefill and the complete flow

**Files:**
- Verify: `resources/js/Pages/UserSide/Orders/payment.tsx:338-368,568-758,894-930`
- Test: `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`

**Interfaces:**
- Consumes authenticated `user.name`, `user.email`, `user.phone`, and the default address returned first by `/api/user/addresses`.
- Produces prefilled editable payment customer and shipping fields through existing state setters.

- [ ] **Step 1: Confirm the existing payment path covers every required field**

Inspect `normalizeCheckoutPayload`, `applySelectedAddress`, and `loadSavedAddress`. Confirm they set customer name, email, phone, address line, region/province, city, barangay, postal code, latitude, and longitude. Do not edit `payment.tsx` unless one of those assignments is actually missing.

- [ ] **Step 2: Run focused and adjacent backend tests**

Run: `php artisan test tests/Feature/UserSide/CustomerRegistrationAddressTest.php tests/Feature/UserSide/UserAddressCoordinateTest.php`

Expected: all tests pass.

- [ ] **Step 3: Run the production frontend build**

Run: `pnpm build`

Expected: Vite exits successfully.

- [ ] **Step 4: Run the full relevant verification**

Run: `php artisan test`

Expected: the suite passes. If unrelated pre-existing failures occur, record the exact failing tests and verify the two focused files still pass.

- [ ] **Step 5: Perform the browser smoke check**

Register a customer using Search, then repeat with GPS denied to verify the inline error and manual retry. Confirm map click and marker drag update the editable address, registration creates a default saved address, and payment displays the registered name, email, phone, and selected shipping address without changing the registration card width.

- [ ] **Step 6: Commit only if verification required a payment fix**

```bash
git add resources/js/Pages/UserSide/Orders/payment.tsx
git commit -m "fix: preload registered address at payment"
```

If no payment edit was needed, skip this commit.
