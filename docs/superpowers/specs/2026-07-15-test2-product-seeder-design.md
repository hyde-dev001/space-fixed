# Test2 Product Seeder Design

## Goal

Provide one reusable retail product for the Urban Kicks Store shop owner (`test2@example.com`) so checkout, order, and logistics flows can be tested without manual product entry.

## Behavior

- Add a standalone `Database\Seeders\Test2ProductSeeder`.
- Find the existing `ShopOwner` by `test2@example.com`; throw a clear `RuntimeException` when the shop owner has not been seeded.
- Use `updateOrCreate` scoped by `shop_owner_id` and SKU `TEST2-SHOE-001`, making repeated runs safe and preventing cross-shop updates.
- Seed **Urban Kicks Test Runner** with description, price `2499.00`, brand `Urban Kicks`, category `shoes`, stock `50`, active status, sizes 7–11, and Black/White colors.
- Do not require an image, media upload, variants, or changes to `DatabaseSeeder`.
- Run explicitly with `php artisan db:seed --class=Test2ProductSeeder` after `ShopOwnerSeeder` has created the account.

## Verification

- A feature test seeds the required shop owner, runs the product seeder twice, and verifies exactly one correctly owned product exists with the expected fields.
- A feature test verifies the seeder gives a clear failure when `test2@example.com` is absent.
