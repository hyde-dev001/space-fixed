<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpenseQueryAndCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_manual_expense_is_posted_and_not_routed_to_approval(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $finance = User::factory()->create([
            'shop_owner_id' => $owner->id,
        ]);
        $response = $this->actingAs($finance, 'user')->postJson('/api/finance/expenses', [
            'date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'category' => 'Travel',
            'description' => 'Travel expense approval boundary',
            'amount' => 100,
            'payment_mode' => 'pay_later',
        ]);

        $response->assertCreated();
        $expenseId = (int) $response->json('id');

        $response->assertJsonPath('status', 'posted');
        $this->assertDatabaseMissing('approvals', [
            'approvable_type' => Expense::class,
            'approvable_id' => $expenseId,
        ]);
        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $owner->id,
            'type' => 'expense_submitted',
            'title' => 'Expense Recorded',
            'requires_action' => false,
            'action_url' => "/shop-owner/erp/finance/expenses?expense={$expenseId}",
        ]);
    }

    public function test_other_category_requires_and_stores_a_custom_category(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $finance = User::factory()->create(['shop_owner_id' => $owner->id]);

        $missingCustomCategory = $this->actingAs($finance, 'user')->postJson('/api/finance/expenses', [
            'date' => now()->toDateString(),
            'category' => 'Other',
            'description' => 'Missing custom category',
            'amount' => 100,
            'payment_mode' => 'pay_later',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $missingCustomCategory->assertStatus(422)->assertJsonValidationErrors('custom_category');

        $created = $this->actingAs($finance, 'user')->postJson('/api/finance/expenses', [
            'date' => now()->toDateString(),
            'category' => 'Other',
            'custom_category' => 'Licenses',
            'description' => 'Custom category expense',
            'amount' => 100,
            'payment_mode' => 'pay_later',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $created->assertCreated()->assertJsonPath('category', 'Licenses');
    }

    public function test_manual_expenses_cannot_use_a_future_business_date(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $finance = User::factory()->create(['shop_owner_id' => $owner->id]);

        $response = $this->actingAs($finance, 'user')->postJson('/api/finance/expenses', [
            'date' => now()->addDay()->toDateString(),
            'category' => 'Travel',
            'description' => 'Future expense',
            'amount' => 100,
            'payment_mode' => 'pay_later',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_manual_expenses_accept_a_past_business_date(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $finance = User::factory()->create(['shop_owner_id' => $owner->id]);

        $response = $this->actingAs($finance, 'user')->postJson('/api/finance/expenses', [
            'date' => now()->subDay()->toDateString(),
            'category' => 'Travel',
            'description' => 'Past expense',
            'amount' => 100,
            'payment_mode' => 'pay_later',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertCreated()->assertJsonPath('category', 'Travel');
    }

    public function test_expense_category_options_and_filters_are_tenant_scoped_and_paginated(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $finance = User::factory()->create(['shop_owner_id' => $owner->id]);

        Expense::create([
            'reference' => 'EXP-TRAVEL-1',
            'date' => now()->subDay()->toDateString(),
            'category' => 'Travel',
            'description' => 'Alpha trip',
            'amount' => 100,
            'status' => 'submitted',
            'shop_id' => $owner->id,
        ]);
        Expense::create([
            'reference' => 'EXP-TRAVEL-2',
            'date' => now()->subDays(2)->toDateString(),
            'category' => 'Travel',
            'description' => 'Beta trip',
            'amount' => 200,
            'status' => 'submitted',
            'shop_id' => $owner->id,
        ]);
        Expense::create([
            'reference' => 'EXP-OTHER-1',
            'date' => now()->subDay()->toDateString(),
            'category' => 'Travel',
            'description' => 'Other shop trip',
            'amount' => 300,
            'status' => 'submitted',
            'shop_id' => $otherOwner->id,
        ]);
        Expense::create([
            'reference' => 'EXP-CUSTOM-1',
            'date' => now()->subDay()->toDateString(),
            'category' => 'Licenses',
            'description' => 'Historical custom category',
            'amount' => 400,
            'status' => 'submitted',
            'shop_id' => $owner->id,
        ]);

        $categories = $this->actingAs($finance, 'user')->getJson('/api/finance/expense-categories');

        $categories->assertOk()
            ->assertJsonFragment(['value' => 'Travel'])
            ->assertJsonFragment(['value' => 'Procurement'])
            ->assertJsonFragment(['value' => 'Licenses']);

        $filtered = $this->actingAs($finance, 'user')->getJson(
            '/api/finance/expenses?filter[category]=Travel&filter[search_all]=Alpha&per_page=1'
        );

        $filtered->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'EXP-TRAVEL-1');

        $allForOwner = $this->actingAs($finance, 'user')->getJson('/api/finance/expenses?per_page=10');

        $allForOwner->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonMissing(['reference' => 'EXP-OTHER-1']);
    }

    public function test_shop_owner_expenses_are_tenant_scoped_and_paginated(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();

        Expense::create([
            'reference' => 'OWNER-EXPENSE-1',
            'date' => now()->subDay()->toDateString(),
            'category' => 'Travel',
            'description' => 'Owner expense',
            'amount' => 100,
            'status' => 'submitted',
            'shop_id' => $owner->id,
        ]);
        Expense::create([
            'reference' => 'OTHER-OWNER-EXPENSE-1',
            'date' => now()->subDay()->toDateString(),
            'category' => 'Travel',
            'description' => 'Other owner expense',
            'amount' => 200,
            'status' => 'submitted',
            'shop_id' => $otherOwner->id,
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->getJson(
            '/api/shop-owner/finance/expenses?per_page=1'
        );

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'OWNER-EXPENSE-1')
            ->assertJsonMissing(['reference' => 'OTHER-OWNER-EXPENSE-1']);

    }
}
