# Test2 Product Seeder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one idempotent active shoe product owned by the `test2@example.com` Urban Kicks Store account for manual checkout, order, and logistics testing.

**Architecture:** A standalone Laravel seeder resolves the target shop by email and uses a shop-scoped SKU key with `updateOrCreate`. One focused feature test proves ownership, field values, rerun idempotency, and clear failure when the prerequisite shop is absent.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit, PHP 8.2.

---

## Isolated worktree setup

Do not use a junction to the main checkout's dependencies. Before Task 1, create a private worktree copy and regenerate its Composer paths:

```powershell
Copy-Item -LiteralPath 'C:\xampp\htdocs\solespace-master\vendor' -Destination '.\vendor' -Recurse
composer dump-autoload
```

Verify the baseline before editing:

```powershell
php vendor/bin/phpunit tests/Feature/ShopOwnerProductIsolationTest.php --display-warnings
```

Expected: the existing test passes. The copied `vendor` directory is ignored and must not be committed.

### Task 1: Standalone Test2 product seeder

**Files:**
- Create: `database/seeders/Test2ProductSeeder.php`
- Create: `tests/Feature/Seeders/Test2ProductSeederTest.php`

- [ ] **Step 1: Write the failing tests**

Create a `RefreshDatabase` feature test with:

```php
public function test_it_idempotently_seeds_the_test2_shop_product(): void
{
    $shop = ShopOwner::factory()->approved()->create(['email' => 'test2@example.com']);

    $this->seed(Test2ProductSeeder::class);
    $this->seed(Test2ProductSeeder::class);

    $this->assertSame(1, Product::where('shop_owner_id', $shop->id)
        ->where('sku', 'TEST2-SHOE-001')->count());
    $this->assertDatabaseHas('products', [
        'shop_owner_id' => $shop->id,
        'sku' => 'TEST2-SHOE-001',
        'name' => 'Urban Kicks Test Runner',
        'price' => 2499.00,
        'stock_quantity' => 50,
        'is_active' => true,
    ]);
}
```

Also assert the stored casts contain sizes `['7', '8', '9', '10', '11']` and colors `['Black', 'White']`. Add a second test expecting `RuntimeException` with `test2@example.com` in the message when the shop is absent.

- [ ] **Step 2: Run the tests and verify RED**

```powershell
php vendor/bin/phpunit tests/Feature/Seeders/Test2ProductSeederTest.php --display-warnings
```

Expected: FAIL because `Database\Seeders\Test2ProductSeeder` does not exist.

- [ ] **Step 3: Implement the minimal standalone seeder**

Create `Test2ProductSeeder` with:

```php
$shop = ShopOwner::where('email', 'test2@example.com')->first()
    ?? throw new RuntimeException('Urban Kicks Store (test2@example.com) was not found. Run ShopOwnerSeeder first.');

Product::updateOrCreate([
    'shop_owner_id' => $shop->id,
    'sku' => 'TEST2-SHOE-001',
], [
    'name' => 'Urban Kicks Test Runner',
    'description' => 'Reusable test shoe for checkout, order, and logistics flows.',
    'price' => 2499.00,
    'brand' => 'Urban Kicks',
    'category' => 'shoes',
    'stock_quantity' => 50,
    'is_active' => true,
    'sizes_available' => ['7', '8', '9', '10', '11'],
    'colors_available' => ['Black', 'White'],
]);
```

Do not add it to `DatabaseSeeder`, create media, variants, or unrelated records.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the Step 2 command. Expected: 2 tests PASS.

- [ ] **Step 5: Run relevant regression tests**

```powershell
php vendor/bin/phpunit tests/Feature/Seeders/Test2ProductSeederTest.php tests/Feature/ShopOwnerProductIsolationTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php --display-warnings
```

Expected: all tests PASS with no warnings.

- [ ] **Step 6: Commit**

```powershell
git add database/seeders/Test2ProductSeeder.php tests/Feature/Seeders/Test2ProductSeederTest.php
git commit -m "test: add Urban Kicks product seeder"
```

### Task 2: Final source verification

**Files:**
- Verify only; do not mutate a configured local database from the isolated worktree.

- [ ] **Step 1: Verify clean source state**

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors or uncommitted source files.

- [ ] **Step 2: Hand off the explicit local command**

After integration, run from the configured main checkout when test data is wanted:

```powershell
php artisan db:seed --class=Test2ProductSeeder
```

This intentionally remains opt-in and requires `ShopOwnerSeeder` to have created `test2@example.com` first.

### Task 3: Seed purchasable size/color stock

**Files:**
- Modify: `database/seeders/Test2ProductSeeder.php`
- Modify: `tests/Feature/Seeders/Test2ProductSeederTest.php`

- [ ] **Step 1: Extend the idempotency test**

Assert parent stock is `1000`, exactly 10 product variants exist after two seeder runs, and every variant has quantity `100`.

- [ ] **Step 2: Run the focused test and verify RED**

```powershell
php vendor/bin/phpunit tests/Feature/Seeders/Test2ProductSeederTest.php --display-warnings
```

Expected: FAIL because the seeder currently creates no `ProductVariant` rows and parent stock remains `50`.

- [ ] **Step 3: Implement the minimal fix**

Set parent stock to `1000`. For each size in `['7', '8', '9', '10', '11']` and color in `['Black', 'White']`, use `ProductVariant::updateOrCreate` scoped by product, size, and color with quantity `100`, an active status, and a deterministic SKU.

- [ ] **Step 4: Verify GREEN and regressions**

Run the focused test, then the existing product-isolation and batch-dispatch regression tests. Expected: all pass; the existing repository-level PHPUnit deprecation may remain.
