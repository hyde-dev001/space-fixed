# Repair Service Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Let authorized repair staff add, replace, or remove one optional service image and show it on the customer-side individual repair-service cards.

**Architecture:** Add a nullable `image_path` to `repair_services`, keep the relative path on Laravel's existing `public` disk, and expose only a derived `image_url` on serialized service payloads. Extend the existing tenant-scoped repair-service endpoints to accept validated multipart data, then reuse the existing ERP service form and customer card flow with a small image picker and a monochrome 16:9 image surface.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit feature tests, Inertia 2, React 18, TypeScript 5.7, Axios, Tailwind CSS 4, Vitest, React Testing Library, Vite 7, pnpm.

## Global Constraints

- Support exactly one optional image per `RepairService`; no gallery, crop editor, CDN, or unrelated redesign.
- Accept only JPG/JPEG/PNG/WEBP images up to 5 MB; validate on the server and keep client validation limited to picker UX.
- Store files below `repair-services/{shop-owner-id}/` on the existing `public` disk with generated filenames.
- Preserve existing permission middleware, authenticated shop-owner resolution, material-template requirements, price approval behavior, package cards, and service selection behavior.
- Serialize `image_url` and hide the relative `image_path`; never expose local filesystem paths or exception details.
- Use the approved monochrome customer/ERP direction from `docs/superpowers/specs/2026-09-20-repair-service-images-design.md` and `docs/mockups/repair-service-image-upload-high-fidelity.html`.
- Write tests before production code for each new backend/frontend behavior and run the focused test until it fails for the intended missing behavior.
- Use `pnpm`, preserve unrelated untracked files, and include a fresh `public/build` only after the final source changes and final rebase.

## File Map

Create:

- `database/migrations/2026_09_20_143613_add_image_path_to_repair_services_table.php` — reversible nullable image-path column.
- `tests/Feature/Repairer/RepairServiceImageTest.php` — upload, validation, replacement/removal, tenant isolation, and public payload coverage.
- `resources/js/Pages/UserSide/Repairs/__tests__/repairShow.service-images.test.ts` — customer-card source contract for image/fallback accessibility.

Modify:

- `app/Models/RepairService.php` — fillable storage path, hidden path, and appended public URL accessor.
- `app/Http/Controllers/Api/RepairServiceController.php` — image validation, storage cleanup, and normalized serialized responses.
- `app/Http/Controllers/UserSide/LandingPageController.php` — add `image_url` to repair-shop and shop-profile service payloads.
- `routes/web.php` — allow method-spoofed POST multipart updates while retaining the existing PUT update route and permission middleware.
- `resources/js/Pages/ERP/repairer/uploadService.tsx` — image state, picker/preview/remove UI, and multipart create/edit payloads.
- `resources/js/Pages/ERP/repairer/__tests__/uploadService.visual.test.tsx` — image picker preview/removal coverage.
- `resources/js/Pages/UserSide/Repairs/repairShow.tsx` — image type and customer service-card image/fallback layout.

---

### Task 1: Add failing backend coverage

**Files:**

- Create: `tests/Feature/Repairer/RepairServiceImageTest.php`
- Reference: `tests/Feature/Repairer/RepairerWorkflowTest.php` helpers and permission setup.

**Interfaces:**

- Consumes: existing `/api/repair-services` POST/GET/PUT behavior and `RepairService` factory/model.
- Produces: executable acceptance coverage for the storage contract used by the controller implementation.

- [ ] **Step 1: Write the failing feature test**

Add a `RefreshDatabase` feature test class with the same `Repairer` role and permissions used by `RepairerWorkflowTest`. Use `Storage::fake('public')`, `UploadedFile::fake()`, and a shop-owner-scoped inventory material. The tests must cover these exact behaviors:

```php
public function test_repairer_can_create_service_with_a_scoped_public_image(): void
{
    Storage::fake('public');
    [$shopOwner, $repairer, $material] = $this->serviceActors();

    $response = $this->actingAs($repairer, 'user')->post('/api/repair-services', [
        'name' => 'Deep Clean',
        'category' => 'Care',
        'price' => '600',
        'duration' => '1 to 2 days',
        'status' => 'Active',
        'material_templates' => [
            ['inventory_item_id' => $material->id, 'default_quantity' => 1],
        ],
        'image' => UploadedFile::fake()->image('deep-clean.jpg', 1200, 675),
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.image_path', null);

    $service = RepairService::query()->latest('id')->firstOrFail();
    $this->assertStringStartsWith("repair-services/{$shopOwner->id}/", $service->image_path);
    Storage::disk('public')->assertExists($service->image_path);
    $response->assertJsonPath('data.image_url', Storage::disk('public')->url($service->image_path));
}
```

The full test file must also include:

- `test_repairer_cannot_upload_a_non_image_or_an_oversized_image`, asserting `422` and `errors.image` for a PDF and a 5121 KB image.
- `test_repairer_can_replace_and_remove_a_service_image`, asserting the old file is deleted after replacement, the new file is persisted, and `remove_image=1` clears the database path and deletes the file.
- `test_repairer_cannot_change_another_shop_service_image`, asserting a repairer from shop B receives `404` for shop A's service and no new file is stored.
- `test_public_service_payload_contains_image_url_and_null_for_services_without_an_image`, asserting active services for the selected shop include `image_url` and never include `image_path`, with `null` for the no-image service.

Keep the request fields identical to the current endpoint contract; only add `image` and `remove_image` to the relevant requests.

- [ ] **Step 2: Run the focused test and verify the intended failure**

Run:

```powershell
php artisan test tests/Feature/Repairer/RepairServiceImageTest.php
```

Expected before implementation: the test fails because `image_path` is not stored/serialized and image validation/storage is absent. Do not change production code to make the test pass before recording this red result.

---

### Task 2: Implement the backend image contract

**Files:**

- Create: `database/migrations/2026_09_20_143613_add_image_path_to_repair_services_table.php`
- Modify: `app/Models/RepairService.php`
- Modify: `app/Http/Controllers/Api/RepairServiceController.php`
- Modify: `routes/web.php`

**Interfaces:**

- Consumes: `image` and `remove_image` requests from the ERP form; current material-template and approval workflows.
- Produces: `RepairService::$image_path`, serialized `image_url`, and generated public-disk files scoped by shop owner.

- [ ] **Step 1: Generate the migration using Laravel's migration generator**

Run:

```powershell
php artisan make:migration add_image_path_to_repair_services_table --table=repair_services
```

Ensure the generated migration at `database/migrations/2026_09_20_143613_add_image_path_to_repair_services_table.php` contains:

```php
public function up(): void
{
    Schema::table('repair_services', function (Blueprint $table): void {
        $table->string('image_path')->nullable()->after('description');
    });
}

public function down(): void
{
    Schema::table('repair_services', function (Blueprint $table): void {
        $table->dropColumn('image_path');
    });
}
```

- [ ] **Step 2: Add the model serialization contract**

In `RepairService`, import `Illuminate\Support\Facades\Storage`, add `image_path` to `$fillable`, hide the stored relative path, append the derived URL, and keep activity logging unchanged:

```php
protected $fillable = [
    // existing fields...
    'approval_workflow_version',
    'image_path',
];

protected $hidden = ['image_path'];

protected $appends = ['image_url'];

public function getImageUrlAttribute(): ?string
{
    return $this->image_path
        ? Storage::disk('public')->url($this->image_path)
        : null;
}
```

Do not add the image path to `getActivitylogOptions()->logOnly(...)`.

- [ ] **Step 3: Add validated image fields to create/update**

Extend the existing `Validator::make` rules in `store` and `update` with:

```php
'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
'remove_image' => ['sometimes', 'boolean'],
```

Use the existing shop-owner resolution and authorization query unchanged. Store a selected create image after the service/material validation path has a valid shop-owner context:

```php
$service = RepairService::create([
    // existing fields...
    'image_path' => null,
]);

try {
    $this->syncMaterialTemplates($service, (array) $request->input('material_templates', []));
    $this->applyServiceImageChange($service, $request, (int) $shopOwnerId);
} catch (ValidationException $e) {
    $this->deleteStoredServiceImage($service->image_path);
    $service->delete();

    return response()->json(['success' => false, 'errors' => $e->errors()], 422);
}
```

The helper must store to `repair-services/{shopOwnerId}` using `UploadedFile::store()` on the `public` disk, update the model only after storage succeeds, delete the previous path only after the model save succeeds, and handle `remove_image=1` by clearing the model path then deleting the old file. If a new-file model save fails, delete the newly stored file and rethrow the original exception.

- [ ] **Step 4: Apply image changes to both update branches**

Call `applyServiceImageChange()` after the existing material-template/approval work succeeds in both the price-approval early-return branch and the normal update branch. This keeps image edits independent of price approval while leaving the current price workflow untouched. The helper signature must be:

```php
private function applyServiceImageChange(
    RepairService $service,
    Request $request,
    int $shopOwnerId,
): void
```

The helper must be a no-op when neither `image` nor `remove_image` is present. Add the string `'image'` to the non-price activity `updated_fields` list only when an image operation was requested; never log the path or file contents.

- [ ] **Step 5: Normalize routes for multipart update requests**

Change the existing protected update route to accept both the current PUT request and method-spoofed POST multipart requests without changing its middleware:

```php
Route::match(['put', 'post'], '{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'update'])
    ->middleware('permission:access-upload-service|access-pricing-services');
```

- [ ] **Step 6: Run migrations and the backend red-green check**

Run:

```powershell
php artisan migrate --env=testing
php artisan test tests/Feature/Repairer/RepairServiceImageTest.php
php artisan test tests/Feature/Repairer/RepairerWorkflowTest.php
```

Expected: the new image tests and existing repair workflow tests pass with zero failures.

---

### Task 3: Add failing frontend image-picker and customer-card coverage

**Files:**

- Modify: `resources/js/Pages/ERP/repairer/__tests__/uploadService.visual.test.tsx`
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/repairShow.service-images.test.ts`

**Interfaces:**

- Consumes: the existing upload-service modal and `repairShow.tsx` source contract.
- Produces: regression checks for local preview/removal and customer image/fallback semantics.

- [ ] **Step 1: Extend the ERP Axios mock and write the failing preview test**

Add `post`, `put`, and `delete` spies to the existing Axios mock. Add a test that opens `Add Service`, changes the labeled `Service image (optional)` input with a `File` of type `image/jpeg`, asserts the preview has alt text `Selected service image preview`, then clicks `Remove image` and asserts the preview is gone. Run the test before adding the component field:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/repairer/__tests__/uploadService.visual.test.tsx
```

Expected: FAIL because the image input and preview controls do not exist.

- [ ] **Step 2: Write the customer source-contract test**

Create `repairShow.service-images.test.ts` using the repository's existing `readFileSync` contract-test pattern:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('repair service images', () => {
  it('renders accessible lazy images and a neutral no-image fallback', () => {
    expect(source).toContain('image_url?: string | null;');
    expect(source).toContain('loading="lazy"');
    expect(source).toContain('No image yet');
    expect(source).toContain('aspect-video');
  });
});
```

Run it before the customer-card change:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/repairShow.service-images.test.ts
```

Expected: FAIL because the type, image surface, and fallback are not present.

---

### Task 4: Implement ERP upload UI and multipart requests

**Files:**

- Modify: `resources/js/Pages/ERP/repairer/uploadService.tsx`
- Modify: `resources/js/Pages/ERP/repairer/__tests__/uploadService.visual.test.tsx`

**Interfaces:**

- Consumes: `Service.image_url`, native `File` input events, and existing form/material state.
- Produces: `FormData` with existing fields plus `image`, `_method`, nested `material_templates`, or `remove_image=1`.

- [ ] **Step 1: Add typed image state and cleanup**

Extend `Service` with `image_url?: string | null`, add parent state for `selectedImageFile`, `imagePreview`, and `removeExistingImage`, and clear all three in `resetForm`. When opening edit, initialize the preview from `service.image_url` without converting it to a `File`. Use `URL.createObjectURL(file)` for local previews and revoke the previous blob URL in an effect cleanup.

- [ ] **Step 2: Add the reusable accessible image field**

Add one small presentational `ServiceImageField` in the same file, used by both Add and Edit modals. It must render:

```tsx
<label htmlFor={inputId}>Service image (optional)</label>
<input
  id={inputId}
  type="file"
  accept="image/jpeg,image/png,image/webp"
  onChange={onChange}
  className="sr-only"
/>
```

When there is no preview, show the keyboard-focusable dashed browse area and helper text `JPG, PNG, or WEBP / Maximum 5 MB / Recommended ratio 16:9`. When there is a preview, render an `img` with alt `Selected service image preview` plus `Replace` and `Remove image` buttons. Use white, black, and neutral gray classes; do not add blue to the new field.

- [ ] **Step 3: Render the field in Add and Edit modals**

Place the field after the existing duration/description content and before material templates in both modals. Give the inputs distinct IDs (`add-service-image` and `edit-service-image`) and preserve every existing duration, description, material-template, and submit control.

- [ ] **Step 4: Build multipart payloads without changing field semantics**

Add a local serializer used by both handlers:

```tsx
const buildServiceFormData = (method: 'POST' | 'PUT'): FormData => {
  const payload = new FormData();
  payload.append('_method', method);
  payload.append('name', formData.name);
  payload.append('category', effectiveCategory);
  payload.append('duration', durationValue);
  payload.append('description', formData.description);
  payload.append('status', method === 'POST' ? 'Active' : formData.status);
  formData.material_templates.forEach((line, index) => {
    payload.append(`material_templates[${index}][inventory_item_id]`, String(line.inventory_item_id));
    payload.append(`material_templates[${index}][default_quantity]`, String(Number(line.default_quantity)));
  });
  if (selectedImageFile) payload.append('image', selectedImageFile);
  else if (method === 'PUT' && removeExistingImage) payload.append('remove_image', '1');
  return payload;
};
```

Keep the current price validation and price omission in edit. Submit add with `axios.post('/api/repair-services', formData)` and edit with `axios.post(`/api/repair-services/${selectedService.id}`, formData)`. Do not set a manual `Content-Type`; Axios must add the multipart boundary. Preserve existing success/error alerts and reset behavior.

- [ ] **Step 5: Run the focused frontend test**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/repairer/__tests__/uploadService.visual.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShow.service-images.test.ts
```

Expected: both new image tests and the existing visual tests pass.

---

### Task 5: Implement customer-side image cards and payload mapping

**Files:**

- Modify: `resources/js/Pages/UserSide/Repairs/repairShow.tsx`
- Modify: `app/Http/Controllers/UserSide/LandingPageController.php`

**Interfaces:**

- Consumes: `RepairService.image_url` from the model and Inertia service payloads.
- Produces: stable responsive customer cards with accessible image/fallback content.

- [ ] **Step 1: Add `image_url` to the customer service type and server maps**

Add `image_url?: string | null` to `RepairService` in `repairShow.tsx`. In both repair-service maps in `LandingPageController` (`buildShopProfileData()` and `repairShow()`), append:

```php
'image_url' => $service->image_url,
```

Do not change package maps.

- [ ] **Step 2: Add the 16:9 image surface above each individual-service card body**

Keep the existing selection click target and card content, but change the individual service card to `h-auto`/`min-h` sizing and add this conditional structure before the service heading:

```tsx
<div className="relative -mx-5 -mt-5 xl:-mx-6 xl:-mt-6 mb-5 aspect-video overflow-hidden bg-gray-100">
  {service.image_url ? (
    <img
      src={service.image_url}
      alt={`${service.title} service`}
      loading="lazy"
      className="h-full w-full object-cover"
    />
  ) : (
    <div
      role="img"
      aria-label={`${service.title} service image unavailable`}
      className="flex h-full w-full flex-col items-center justify-center bg-gray-100 text-gray-500"
    >
      <svg aria-hidden="true" ... />
      <span className="mt-2 text-xs font-medium">No image yet</span>
    </div>
  )}
  <span className="absolute left-3 top-3 ...">{service.category}</span>
</div>
```

Move the category badge out of the heading grid so it is over the image. Keep the selection circle, description list, price, duration icon, click handler, and package cards unchanged. Keep the image surface black/white/gray only.

- [ ] **Step 3: Run customer and existing layout tests**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/repairShow.service-images.test.ts resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts resources/js/Pages/UserSide/Repairs/__tests__/repairShow.dark-mode.test.ts
```

Expected: all selected tests pass.

---

### Task 6: Full verification, fresh build, and delivery preparation

**Files:**

- Modify generated `public/build/*` only from the final `pnpm run build` output.
- Do not stage `.pnpm-store/`, `.tmp/`, or `docs/qa/repair-and-courier-testing-notes.md`.

- [ ] **Step 1: Run focused backend and frontend verification**

```powershell
php artisan test tests/Feature/Repairer/RepairServiceImageTest.php tests/Feature/Repairer/RepairerWorkflowTest.php
pnpm exec vitest run resources/js/Pages/ERP/repairer/__tests__/uploadService.visual.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShow.service-images.test.ts
```

- [ ] **Step 2: Run the full project checks**

```powershell
pnpm run test:frontend
composer test
```

Do not report a TypeScript or lint pass because the repository has no configured TypeScript compiler or frontend lint script.

- [ ] **Step 3: Run the production build once after source verification**

```powershell
pnpm run build
```

Confirm the build exits with code 0 and review that only the expected `public/build` manifest/assets changed.

- [ ] **Step 4: Browser-verify the feature**

Using the existing authenticated local app, verify at desktop and 390px mobile widths:

1. Add Service opens the optional image field, accepts a valid image, shows its preview, and Remove image restores the empty state.
2. Creating a service with an image succeeds and the service table refreshes.
3. The matching customer repair-shop card shows the uploaded image; a service without one shows `No image yet`.
4. Edit Replace and Remove image update the customer card after refresh.
5. Package cards and service selection still work.

- [ ] **Step 5: Inspect and deliver the final feature branch**

Run:

```powershell
git status --short
git diff --stat
git diff --check
```

Review the complete diff, stage only the intended source, test, migration, plan, and fresh `public/build` files, then commit with:

```powershell
git add app database resources routes tests public/build docs/superpowers/plans/2026-09-20-repair-service-images.md
git diff --cached --check
git commit -m "feat: add repair service images"
```

Before pushing, follow `docs/git-workflow.md`: fetch/rebase `origin/solespace-b`, rerun the focused checks and final build after the rebase if source changes, inspect staged diff, then push only `feature/erp-ui-approval-updates`. The user will create the PR.

## Plan self-review

- Spec coverage: storage, validation, replacement/removal, tenant scoping, normalized URL, ERP preview, customer fallback, package preservation, tests, browser checks, and fresh build each have an explicit task.
- Placeholder scan: no `TBD`, `TODO`, or unspecified implementation step is required; exact paths, commands, labels, and payload field names are provided.
- Type consistency: backend uses `image_path` internally and `image_url` externally; frontend uses optional `image_url`, `selectedImageFile`, `imagePreview`, and `removeExistingImage`; multipart names match Laravel nested-array parsing.
