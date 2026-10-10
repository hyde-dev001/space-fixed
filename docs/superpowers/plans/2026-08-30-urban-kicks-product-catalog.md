# Urban Kicks Product Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Seed a broad, repeatable product catalog for Urban Kicks Store.

**Architecture:** Extend the existing `Test2ProductSeeder` with declarative product definitions and generated size/color variants. Register it after shop-owner creation so repeated `db:seed` runs update the same SKUs without affecting other shops.

**Tech Stack:** Laravel 12, Eloquent models, PHP 8.2, PHPUnit/Pest.

## Global Constraints

- Scope products to `test2@example.com` / Urban Kicks Store.
- Use stable SKUs and `updateOrCreate` for idempotent seeding.
- Do not delete existing products or modify other shop owners.
- Reuse the existing `Product` and `ProductVariant` models.

### Task 1: Expand the Urban Kicks catalog

**Files:**
- Modify: `database/seeders/Test2ProductSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Database/UrbanKicksProductSeederTest.php`

- [ ] Add a failing test for catalog count, category coverage, stable reruns, and variant creation.
- [ ] Define the product catalog and generate variants from each product's sizes/colors.
- [ ] Register the seeder in `DatabaseSeeder` after `ShopOwnerSeeder`.
- [ ] Run the focused seeder test and Laravel formatter/checks.
- [ ] Run `git diff --check`, commit intended files, and report the result.
