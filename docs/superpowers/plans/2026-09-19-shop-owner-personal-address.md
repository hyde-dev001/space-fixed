# Shop Owner Personal Details and Address Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add shop-owner suffix, age, and a separate Leaflet/GPS-backed personal address while preserving the existing shop address and Super Admin approval flow.

**Architecture:** Add nullable personal-detail/address columns directly to shop_owners for one owner address, validate and persist them through the existing register/resubmit transactions, and reuse CustomerAddressMapPicker only for the new personal address. Keep the current Cavite/geofence shop map untouched; expose the new personal fields in the existing Super Admin registration detail payload/modal without changing approval decisions.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Leaflet 1.9, Vitest, PHPUnit.

## Global Constraints

- Preserve the existing Shop Information address, Cavite-only policy, shop coordinates, geofence behavior, document workflow, and approval/rejection decision service.
- Use pnpm for frontend commands.
- Add no dependency; reuse CustomerAddressMapPicker, registrationAddress, NominatimService, existing validation, and existing Inertia patterns.
- New database columns are nullable so existing shop-owner rows and legacy rejected applications remain readable.
- New registrations and resubmissions must provide valid owner age and a complete Philippine personal address selection.
- Do not commit or alter unrelated user work; the existing untracked .pnpm-store/ and .tmp/ directories are out of scope.

## File map

- Create database/migrations/2026_09_19_093146_add_personal_details_and_address_to_shop_owners.php for reversible additive schema changes.
- Modify app/Models/ShopOwner.php for fillable fields and age/coordinate casts.
- Modify app/Http/Controllers/ShopOwnerAuthController.php for validation, reverse resolution, persistence, and resubmission payloads.
- Modify resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx for personal fields, picker state, validation, and multipart payload fields.
- Modify app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php for authorized review data.
- Modify resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx for review types and display.
- Modify existing registration and approval feature tests for the new contract and compatibility.

### Task 1: Add failing backend and approval coverage

Files:
- Modify tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php
- Modify tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php
- Modify tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php
- Modify tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php
- Modify tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php
- Modify tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
- Modify tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php

Interfaces:
- Registration requests will include suffix, age, address, address_region, address_province, address_city, address_barangay, address_postal_code, address_latitude, and address_longitude.
- Approval coverage will consume the existing registration detail and approval endpoints.

- [ ] Step 1: Add one deterministic valid personal-address fixture to every registration payload helper and literal registration payload.

    'suffix' => 'Jr.',
    'age' => 32,
    'address' => '123 Rizal Street, Ermita, Manila',
    'address_region' => 'National Capital Region',
    'address_province' => 'Metro Manila',
    'address_city' => 'Manila',
    'address_barangay' => 'Ermita',
    'address_postal_code' => '1000',
    'address_latitude' => 14.5832,
    'address_longitude' => 120.9822,

Keep these personal fields separate from the existing business_address, shop_address, shop_latitude, and shop_longitude fields.

- [ ] Step 2: Add a persistence assertion to the canonical registration test.

    $this->assertDatabaseHas('shop_owners', [
        'email' => 'auth-register@solespaceph.com',
        'suffix' => 'Jr.',
        'age' => 32,
        'address' => '123 Rizal Street, Ermita, Manila',
        'address_city' => 'Manila',
        'address_barangay' => 'Ermita',
        'address_postal_code' => '1000',
    ]);

- [ ] Step 3: Add a failing validation test that removes address_latitude and address_longitude from a valid payload and expects HTTP 422 with both coordinate validation errors.

- [ ] Step 4: Add a resubmission update assertion that changes suffix, age, address, city, postal code, and coordinates, posts to the signed resubmission URL, and asserts the owner returns to pending with the new values persisted.

- [ ] Step 5: Extend the approval end-to-end test to assert the personal fields on the pending owner before running the existing Super Admin approve request. Keep all existing status, document, module, audit, and notification assertions unchanged.

- [ ] Step 6: Run the focused tests and confirm the new assertions fail before implementation.

    php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php

Expected result: the new persistence/validation assertions fail because the schema and controller contract are not implemented yet.

### Task 2: Add the additive schema and model contract

Files:
- Create database/migrations/2026_09_19_000001_add_personal_details_and_address_to_shop_owners.php
- Modify app/Models/ShopOwner.php

Interfaces:
- Produces nullable shop_owners columns consumed by registration, resubmission, and Super Admin review.

- [ ] Step 1: Create a reversible migration with exactly these nullable columns:

    Schema::table('shop_owners', function (Blueprint $table): void {
        $table->string('suffix', 20)->nullable()->after('last_name');
        $table->unsignedTinyInteger('age')->nullable()->after('suffix');
        $table->text('address')->nullable()->after('age');
        $table->string('address_region')->nullable()->after('address');
        $table->string('address_province')->nullable()->after('address_region');
        $table->string('address_city')->nullable()->after('address_province');
        $table->string('address_barangay')->nullable()->after('address_city');
        $table->string('address_postal_code', 10)->nullable()->after('address_barangay');
        $table->decimal('address_latitude', 10, 8)->nullable()->after('address_postal_code');
        $table->decimal('address_longitude', 11, 8)->nullable()->after('address_latitude');
    });

The down method must drop only these columns and must not touch business_address, shop_address, or shop coordinates.

- [ ] Step 2: Add the new fields to ShopOwner::$fillable and add these casts without changing existing status or shop-coordinate casts:

    'age' => 'integer',
    'address_latitude' => 'decimal:8',
    'address_longitude' => 'decimal:8',

- [ ] Step 3: Run the migration-backed persistence test.

    php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php --filter="persists_shop_owner_personal_details"

Expected result: it reaches the controller contract instead of failing because columns are missing.

### Task 3: Implement registration, resubmission, and server-side address resolution

File:
- Modify app/Http/Controllers/ShopOwnerAuthController.php

Interfaces:
- Consumes the personal-address request fields from Task 1.
- Produces resubmission.form keys: suffix, age, address, addressRegion, addressProvince, addressCity, addressBarangay, addressPostalCode, addressLatitude, and addressLongitude.

- [ ] Step 1: Inject the existing NominatimService and create one private resolvePersonalAddress(array $validated): array helper used by both register and resubmit.

The helper will call reverse() with the submitted coordinates, require country_code ph plus region, province, city, and barangay, replace the submitted normalized components/postal code with the reverse-geocoded values, and throw ValidationException on lookup failure or incomplete/non-Philippine results. Keep the submitted address line as the owner-entered display address.

- [ ] Step 2: Reuse one personal-address rule set in both register and resubmit:

    'suffix' => ['nullable', 'string', 'max:20'],
    'age' => ['required', 'integer', 'min:18', 'max:120'],
    'address' => ['required', 'string', 'max:500'],
    'address_region' => ['required', 'string', 'max:255'],
    'address_province' => ['required', 'string', 'max:255'],
    'address_city' => ['required', 'string', 'max:255'],
    'address_barangay' => ['required', 'string', 'max:255'],
    'address_postal_code' => ['nullable', 'string', 'max:10'],
    'address_latitude' => ['required', 'numeric', 'between:4.5,21.5'],
    'address_longitude' => ['required', 'numeric', 'between:116,127'],

Use clear age/address/coordinate messages. Call resolvePersonalAddress after request validation and before the existing Cavite shop-location policy; the existing shop policy must continue to govern only shop location.

- [ ] Step 3: Persist all personal fields in the existing ShopOwner::create, rejected reapplication update, and rejected resubmission forceFill arrays. Keep the existing transactions and document lifecycle unchanged.

- [ ] Step 4: Extend showResubmissionForm() with these form values:

    'suffix' => (string) ($shopOwner->suffix ?? ''),
    'age' => $shopOwner->age,
    'address' => (string) ($shopOwner->address ?? ''),
    'addressRegion' => (string) ($shopOwner->address_region ?? ''),
    'addressProvince' => (string) ($shopOwner->address_province ?? ''),
    'addressCity' => (string) ($shopOwner->address_city ?? ''),
    'addressBarangay' => (string) ($shopOwner->address_barangay ?? ''),
    'addressPostalCode' => (string) ($shopOwner->address_postal_code ?? ''),
    'addressLatitude' => $shopOwner->address_latitude,
    'addressLongitude' => $shopOwner->address_longitude,

Keep all existing shop form values in the same payload.

- [ ] Step 5: Add deterministic Nominatim fakes to valid registration tests. Return a reverse-geocode payload with country_code ph, National Capital Region, Metro Manila, Manila, Ermita, and postcode 1000. Do not make external network calls in tests.

- [ ] Step 6: Run the registration/location/email test set.

    php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php

Expected result: all existing Cavite/location/email behavior and new personal-address assertions pass.

### Task 4: Add the personal-details UI and shared Leaflet/GPS picker

Files:
- Modify resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx
- Reuse resources/js/components/address/CustomerAddressMapPicker.tsx
- Reuse resources/js/Pages/UserSide/Auth/registrationAddress.ts

Interfaces:
- Consumes the resubmission.form keys from Task 3.
- Produces multipart fields matching Task 3.
- Leaves businessAddress, shopAddress, shopLatitude, and shopLongitude in separate state and payload paths.

- [ ] Step 1: Extend ResubmissionPayload.form and formData with suffix, age, address, addressRegion, addressProvince, addressCity, addressBarangay, and addressPostalCode. Add separate RegistrationAddress | null state initialized from saved personal coordinates/components. Do not reuse geoLat, geoLng, geoAddress, or the existing shop map refs.

- [ ] Step 2: Add a handlePersonalAddressChange(location: RegistrationAddress) callback that sets the personal location and fills address, region, province, city, barangay, and postal code. Clear only personal address errors. Use CustomerAddressMapPicker with embeddedInForm and a controlled coordinate value derived from personalAddressLocation.

- [ ] Step 3: Add suffix and age beside the existing name/contact fields. Add a Personal Address panel containing the address line, postal code, province, city/municipality, and barangay values followed by the picker. Use existing Label/Input/ComponentCard styling. Keep the current Step 2 Shop Address panel unchanged.

- [ ] Step 4: Validate age as a whole number from 18 through 120, require address, and require personalAddressLocation. Add personal error keys to getFirstInvalidStep().

- [ ] Step 5: Append the personal fields in handleSubmit() while leaving existing shop appends separate:

    submitData.append('suffix', formData.suffix);
    submitData.append('age', formData.age);
    submitData.append('address', formData.address);
    submitData.append('address_region', personalAddressLocation.region);
    submitData.append('address_province', personalAddressLocation.province);
    submitData.append('address_city', personalAddressLocation.city);
    submitData.append('address_barangay', personalAddressLocation.barangay);
    submitData.append('address_postal_code', personalAddressLocation.postalCode);
    submitData.append('address_latitude', String(personalAddressLocation.latitude));
    submitData.append('address_longitude', String(personalAddressLocation.longitude));

The picker Use My Location action is the automatic-fill source for personal address. Existing shop Use My GPS remains independent.

- [ ] Step 6: Run focused frontend tests and the production build.

    pnpm exec vitest run resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx resources/js/Pages/UserSide/Auth/__tests__/registrationAddress.test.ts
    pnpm run build

Expected result: existing Leaflet/GPS/reverse-geocode tests pass and Vite accepts the new state/imports.

### Task 5: Expose personal details in the existing Super Admin review modal

Files:
- Modify app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php
- Modify resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx
- Modify resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts

Interfaces:
- Produces optional frontend fields so legacy/factory-created rows with null personal data still render safely.
- Does not change approve(), reject(), status transitions, document review contracts, or decision request payloads.

- [ ] Step 1: Add the personal columns to the controller select([...]) and return suffix, age, address, addressRegion, addressProvince, addressCity, addressBarangay, and addressPostalCode from formatRegistration(). Do not expose personal coordinates unless the existing review UX needs them.

- [ ] Step 2: Extend the Registration interface with optional personal fields and display suffix/age/personal address in the existing Personal Information panel. Format non-empty address components and show Not provided for legacy rows. Keep businessAddress in the Business Information panel so the two addresses are visibly distinct.

- [ ] Step 3: Extend the existing frontend view fixture with personal values and assert both the personal address and business address are present when the detail modal is opened. Keep fields optional for legacy fixtures.

- [ ] Step 4: Run Super Admin checks.

    pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
    php artisan test tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php

Expected result: review displays both addresses and the existing approval endpoint still applies approval with its current document/module/audit side effects.

### Task 6: Final verification and scope review

Files:
- Inspect only all changed files from Tasks 1–5.

- [ ] Step 1: Run the relevant backend, frontend, build, and diff checks.

    php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
    pnpm run test:frontend
    pnpm run build
    git diff --check

- [ ] Step 2: Inspect the final working tree and diff.

    git status --short
    git diff --stat
    git diff

Confirm only the approved spec/plan and requested implementation/test files changed; leave .pnpm-store/ and .tmp/ untouched and remove no existing shop-address or approval code.

- [ ] Step 3: Record sequential standards/spec/risk/reuse/dead-code checks: personal and shop addresses remain separate; server validation and coordinate reverse resolution are authoritative; the migration is additive/reversible; resubmission is covered; approval uses the existing decision service; and no unused imports/debug output/temporary code remain.
