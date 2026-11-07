# Product Price Range Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with review checkpoints. Subagent dispatch is disabled by the repository operating model, so execute inline and sequentially.

**Goal:** Add an Apply-driven, URL-backed price-range filter to the public SoleSpace product catalog with server-side inclusive filtering and an accessible modal matching the existing Color filter.

**Architecture:** Keep the existing catalog state and modal in `Products.tsx`, add `min_price`/`max_price` URL state and `filter[price_min]`/`filter[price_max]` API parameters, and extend `ProductController@index` with validated Query Builder range filters. Reuse existing menu, modal styling, pagination reset, and source-contract tests.

**Tech Stack:** Laravel 12, PHP 8.2, Spatie Query Builder, React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, PHPUnit.

## Global Constraints

- Apply only after `Apply`; Cancel, close, backdrop click, and Escape discard draft values.
- Use inclusive comparisons: `min_price <= price <= max_price`.
- Empty bounds are valid for open-ended ranges.
- Reject negative, non-numeric, and minimum-greater-than-maximum values at UI and API boundaries.
- Reset pagination to page 1 whenever the range is applied or cleared.
- Preserve existing search, category, color, sorting, layout, and authorization behavior.
- Do not add a slider dependency, component library, or new design tokens.
- Do not edit unrelated untracked files, generated files, `vendor/`, `node_modules/`, or lockfiles.
- Do not commit unless the user explicitly requests a commit.

## File Map

- Modify `resources/js/Pages/UserSide/Products/Products.tsx`: URL parsing, price state, API parameters, menu item, modal, validation, and Escape handling.
- Modify `resources/js/Pages/UserSide/Products/Products.layout.test.ts`: source-contract assertions.
- Modify `app/Http/Controllers/Api/ProductController.php`: public filter validation and range callbacks.
- Create `tests/Feature/PublicProductPriceFilterTest.php`: inclusive range and invalid-bound endpoint tests.

### Task 1: Add the failing frontend contract

**Files:** Modify `resources/js/Pages/UserSide/Products/Products.layout.test.ts`.

- [ ] Add this test inside the existing Products layout `describe` block:

```ts
  it('adds an apply-driven price range filter with URL-backed API parameters', () => {
    expect(productsSource).toContain('data-testid="price-filter-menu-item"');
    expect(productsSource).toContain('Minimum price');
    expect(productsSource).toContain('Maximum price');
    expect(productsSource).toContain('filter[price_min]');
    expect(productsSource).toContain('filter[price_max]');
    expect(productsSource).toContain("params.set('min_price'");
    expect(productsSource).toContain("params.set('max_price'");
    expect(productsSource).toContain("event.key === 'Escape'");
    expect(productsSource).toContain('The minimum price must be less than or equal to the maximum price.');
  });
```

- [ ] Run `pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts`.
- [ ] Confirm the new test fails because the price-range contract is not in `Products.tsx` yet.

### Task 2: Add the failing backend regression tests

**Files:** Create `tests/Feature/PublicProductPriceFilterTest.php`.

- [ ] Create this focused PHPUnit feature test:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProductPriceFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_products_apply_inclusive_minimum_and_maximum_price_filters(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $below = $this->productFor($shop, 'price-below', 1999);
        $minimum = $this->productFor($shop, 'price-minimum', 2000);
        $maximum = $this->productFor($shop, 'price-maximum', 3000);
        $above = $this->productFor($shop, 'price-above', 3001);

        $response = $this->getJson('/api/products?filter[price_min]=2000&filter[price_max]=3000');

        $response->assertOk();
        $ids = collect($response->json('products.data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$minimum->id, $maximum->id], $ids);
        $this->assertNotContains($below->id, $ids);
        $this->assertNotContains($above->id, $ids);
    }

    public function test_public_products_reject_a_minimum_price_above_the_maximum_price(): void
    {
        $this->getJson('/api/products?filter[price_min]=3000&filter[price_max]=2000')
            ->assertStatus(422)
            ->assertJsonPath('errors.filter.price_min.0', 'The minimum price must be less than or equal to the maximum price.');
    }

    private function productFor(ShopOwner $shop, string $slug, float $price): Product
    {
        return Product::create([
            'shop_owner_id' => $shop->id,
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'price' => $price,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }
}
```

- [ ] Run `php artisan test --filter=PublicProductPriceFilterTest`.
- [ ] Confirm the new tests fail because the public API does not allow the two price filters or reversed-range validation yet.

### Task 3: Implement the server-side price range boundary

**Files:** Modify `app/Http/Controllers/Api/ProductController.php` in `index`.

- [ ] Before the existing `try` block, validate `filter.price_min` and `filter.price_max` as nullable numeric values with `min:0`, read the values, and return JSON 422 with the exact message `The minimum price must be less than or equal to the maximum price.` when both are present and minimum exceeds maximum.

```php
        $request->validate([
            'filter.price_min' => ['nullable', 'numeric', 'min:0'],
            'filter.price_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        $priceMin = $request->input('filter.price_min');
        $priceMax = $request->input('filter.price_max');
        if ($priceMin !== null && $priceMax !== null && (float) $priceMin > (float) $priceMax) {
            return response()->json([
                'success' => false,
                'message' => 'The minimum price must be less than or equal to the maximum price.',
                'errors' => ['filter.price_min' => ['The minimum price must be less than or equal to the maximum price.']],
            ], 422);
        }
```

- [ ] Add these callbacks beside the existing color callback in `allowedFilters`:

```php
                    AllowedFilter::callback('price_min', function ($query, $value) {
                        $query->where('price', '>=', (float) $value);
                    }),
                    AllowedFilter::callback('price_max', function ($query, $value) {
                        $query->where('price', '<=', (float) $value);
                    }),
```

- [ ] Rerun `php artisan test --filter=PublicProductPriceFilterTest` and confirm both tests pass.

### Task 4: Implement the frontend URL-backed modal and API parameters

**Files:** Modify `resources/js/Pages/UserSide/Products/Products.tsx`.

- [ ] Add `PriceRange` state initialized from `min_price`/`max_price`, synchronize it in the existing URL effect, and include the active range in the product-fetch effect dependencies.
- [ ] Append `filter[price_min]` and `filter[price_max]` only when the corresponding active bound is non-empty:

```tsx
      if (priceRange.min) params.append('filter[price_min]', priceRange.min);
      if (priceRange.max) params.append('filter[price_max]', priceRange.max);
```

- [ ] Add URL update, open/close, clear, and Apply handlers. Apply must validate finite non-negative bounds, reject reversed ranges with the exact inline error message, update `min_price`/`max_price`, reset page 1, and close the modal.

```tsx
  const updatePriceRangeQueryParam = (range: PriceRange) => {
    const params = new URLSearchParams(window.location.search);
    range.min ? params.set('min_price', range.min) : params.delete('min_price');
    range.max ? params.set('max_price', range.max) : params.delete('max_price');
    const query = params.toString();
    window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
  };
```

- [ ] Add a keydown effect while open to close on Escape and focus the minimum input.
- [ ] Add `Price range` after Color with `data-testid="price-filter-menu-item"`, active Philippine-peso label, and the existing menu role/focus styles.
- [ ] Add the responsive dialog with visible `Minimum price` and `Maximum price` labels, numeric/inputmode controls, inline `role="alert"` validation, 44px controls, and `Clear`, `Cancel`, `Apply` actions. Reuse the existing Color modal's scrim, border, shadow, radius, max-height, and overflow classes.
- [ ] Rerun `pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts` and confirm all assertions pass.

### Task 5: Review and verify

**Files:** Review the four changed application/test files and the spec/plan.

- [ ] Run focused checks:

```powershell
php artisan test --filter=PublicProductPriceFilterTest
pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts
```

- [ ] Run broader frontend checks:

```powershell
pnpm run test:frontend
pnpm run build
```

- [ ] Run `git diff --check`, inspect `git status --short`, `git diff --stat`, and the full scoped diff. Confirm unrelated untracked files remain untouched and no debug output or temporary files were added.
- [ ] If the local Laravel/Vite app is runnable, browser-check desktop/mobile modal open, labels, validation, Cancel/Escape, Apply with `2000`–`3000`, URL state, filtered cards, and Clear. If it cannot be started safely, report browser verification as not run.

No commit step is included because the repository instructions require an explicit user request before committing.
