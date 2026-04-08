# Retail Promos ProductShow Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-ready retail promo system that connects shop-owner promo management, ProductShow voucher claiming, and checkout auto-application with sale-first-then-voucher pricing.

**Architecture:** Add a dedicated Laravel promo domain (campaigns, product targeting, voucher claims) and expose scoped APIs for shop owners and customers. Replace ProductShow static vouchers and discount page mock data with API-driven state. Compute effective totals in checkout by applying active sale first, then eligible claimed voucher.

**Tech Stack:** Laravel 11, Inertia, React + TypeScript, PHPUnit feature tests, Vitest + Testing Library.

---

## File Structure

### Create
- `app/Models/PromoCampaign.php`: promo campaign entity and relationships.
- `app/Models/VoucherClaim.php`: customer claim records and redemption state.
- `app/Services/PromoPricingService.php`: centralized sale and voucher eligibility/apply logic.
- `app/Http/Controllers/ShopOwner/PromoCampaignController.php`: shop-owner promo CRUD APIs.
- `app/Http/Controllers/UserSide/ProductVoucherController.php`: claim endpoint for ProductShow.
- `database/migrations/2026_04_08_120000_create_promo_campaigns_table.php`: campaign schema.
- `database/migrations/2026_04_08_120100_create_promo_campaign_products_table.php`: product targeting pivot schema.
- `database/migrations/2026_04_08_120200_create_voucher_claims_table.php`: claims schema.
- `tests/Feature/ShopOwner/PromoCampaignApiTest.php`: promo CRUD authorization and validation tests.
- `tests/Feature/UserSide/ProductVoucherClaimTest.php`: claim behavior and duplicate protection tests.
- `tests/Feature/UserSide/CheckoutPromoApplicationTest.php`: sale-first-then-voucher pricing tests.
- `resources/js/Pages/ShopOwner/Orders/order management/__tests__/discount.api.test.tsx`: API-driven discount page tests.
- `resources/js/Pages/UserSide/Products/__tests__/ProductShow.vouchers.test.tsx`: dynamic voucher strip tests.
- `resources/js/Pages/UserSide/Orders/__tests__/payment.auto-apply-voucher.test.tsx`: checkout auto-apply tests.

### Modify
- `routes/shop-owner-api.php`: add promo management routes.
- `routes/web.php`: add customer claim route and (if needed) checkout voucher route.
- `app/Http/Controllers/UserSide/LandingPageController.php`: include promo context in ProductShow payload.
- `app/Http/Controllers/UserSide/CheckoutController.php`: apply claimed vouchers after sale-adjusted subtotal.
- `resources/js/Pages/ShopOwner/Orders/order management/discount.tsx`: replace local campaigns with API CRUD state.
- `resources/js/Pages/UserSide/Products/ProductShow.tsx`: remove static vouchers and use backend campaigns.
- `resources/js/Pages/UserSide/Orders/payment.tsx`: show applied voucher metadata and totals.

---

### Task 1: Promo Schema and Domain Models

**Files:**
- Create: `database/migrations/2026_04_08_120000_create_promo_campaigns_table.php`
- Create: `database/migrations/2026_04_08_120100_create_promo_campaign_products_table.php`
- Create: `database/migrations/2026_04_08_120200_create_voucher_claims_table.php`
- Create: `app/Models/PromoCampaign.php`
- Create: `app/Models/VoucherClaim.php`
- Test: `tests/Feature/ShopOwner/PromoCampaignApiTest.php`

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\ShopOwner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromoCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_promo_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('promo_campaigns'));
        $this->assertTrue(Schema::hasTable('promo_campaign_products'));
        $this->assertTrue(Schema::hasTable('voucher_claims'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PromoCampaignApiTest::test_promo_tables_exist`
Expected: FAIL with missing table assertion.

- [ ] **Step 3: Add migrations**

```php
// database/migrations/2026_04_08_120000_create_promo_campaigns_table.php
Schema::create('promo_campaigns', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
    $table->enum('kind', ['voucher', 'sale']);
    $table->enum('scope', ['shop_wide', 'product_specific']);
    $table->string('name');
    $table->string('code')->nullable();
    $table->enum('discount_mode', ['percentage', 'fixed']);
    $table->decimal('value', 12, 2);
    $table->decimal('min_spend', 12, 2)->default(0);
    $table->unsignedInteger('usage_limit')->nullable();
    $table->unsignedInteger('used_count')->default(0);
    $table->timestamp('start_at');
    $table->timestamp('end_at');
    $table->enum('status', ['draft', 'scheduled', 'active', 'expired', 'disabled'])->default('draft');
    $table->enum('stacking_mode', ['combinable', 'exclusive'])->default('combinable');
    $table->timestamps();

    $table->index(['shop_owner_id', 'status']);
    $table->unique(['shop_owner_id', 'code']);
});

// database/migrations/2026_04_08_120100_create_promo_campaign_products_table.php
Schema::create('promo_campaign_products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('promo_campaign_id')->constrained('promo_campaigns')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['promo_campaign_id', 'product_id']);
    $table->index('product_id');
});

// database/migrations/2026_04_08_120200_create_voucher_claims_table.php
Schema::create('voucher_claims', function (Blueprint $table) {
    $table->id();
    $table->foreignId('promo_campaign_id')->constrained('promo_campaigns')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
    $table->enum('status', ['claimed', 'redeemed', 'expired', 'cancelled'])->default('claimed');
    $table->timestamp('claimed_at')->nullable();
    $table->timestamp('redeemed_at')->nullable();